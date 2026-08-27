<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Enums\BillingStatus;
use App\Support\Metering\ProjectSpend;
use Carbon\CarbonInterface;
use Database\Factories\ProjectSubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one project is entitled to.
 *
 * Deliberately **not** `BelongsToProject`. Every other tenant-owned model is
 * read from inside a project and scoped to it; this one is read to decide
 * whether there is a project to be inside. The tick reads every row at once,
 * the administrative panel reads all of them across tenants, and the webhook
 * that updates one arrives with no session and no current project at all.
 * Scoping it would make each of those three either fail closed — every project
 * unentitled, engine silently stopped — or need `acrossProjects()`, which is
 * the annotation that is supposed to be rare.
 *
 * The row is authoritative about *entitlement*, never about *spend*. What a
 * project has cost is `pipeline_runs` plus `assistant_messages`; see
 * {@see ProjectSpend}.
 *
 * @property string $id
 * @property string $project_id
 * @property int|null $billing_user_id
 * @property string $plan
 * @property int $plan_version
 * @property BillingStatus $status
 * @property array<string, int|null> $limit_overrides
 * @property Carbon|null $period_started_at
 * @property Carbon|null $period_ends_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $grace_ends_at
 * @property Carbon|null $canceled_at
 * @property string|null $stripe_id
 * @property string|null $stripe_status
 * @property string|null $stripe_price
 */
class ProjectSubscription extends Model
{
    /** @use HasFactory<ProjectSubscriptionFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'project_id',
        'billing_user_id',
        'plan',
        'plan_version',
        'status',
        'limit_overrides',
        'period_started_at',
        'period_ends_at',
        'trial_ends_at',
        'grace_ends_at',
        'canceled_at',
        'stripe_id',
        'stripe_status',
        'stripe_price',
    ];

    /**
     * The json column defaults in the database and is null on the instance that
     * created the row — the trap {@see Project} documents, and one that bites
     * harder here because the reader spreads it into a plan's limits.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'limit_overrides' => '{}',
        'plan_version' => 1,
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billing_user_id');
    }

    /**
     * The plan as sold, with this customer's overrides on top.
     *
     * Read under `plan_version` rather than under today's, which is the whole
     * reason that column exists: re-pricing publishes a new list and must not
     * move the one somebody is already paying against.
     */
    public function plan(): Plan
    {
        $catalog = app(PlanCatalog::class);

        // The trial goes through the same versioned lookup as everything else.
        $plan = $catalog->get($this->plan, $this->plan_version);

        $overrides = $this->limit_overrides;

        return $overrides === [] ? $plan : $plan->with($overrides);
    }

    /**
     * Whether the free window has run out.
     *
     * Time only. The unit and money caps of a trial are limits like any other
     * plan's and are checked where all limits are checked; this is the one
     * thing about a trial that no counter can see.
     */
    public function trialHasExpired(): bool
    {
        return $this->status === BillingStatus::Trialing
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }

    /** Whether dunning has run out and the project should stop entirely. */
    public function graceHasExpired(): bool
    {
        return $this->status === BillingStatus::PastDue
            && $this->grace_ends_at !== null
            && $this->grace_ends_at->isPast();
    }

    /**
     * The window the usage counters are keyed to.
     *
     * Never null: a subscription somebody forgot to stamp still has to count
     * something, and counting into a null key would give every such project an
     * unlimited month. Falls back to the row's own creation, which is the
     * earliest defensible answer.
     */
    public function periodStart(): CarbonInterface
    {
        return $this->period_started_at ?? $this->created_at ?? Carbon::now()->startOfDay();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BillingStatus::class,
            'limit_overrides' => 'array',
            'plan_version' => 'integer',
            'period_started_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }
}
