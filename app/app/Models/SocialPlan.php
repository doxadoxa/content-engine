<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use App\Social\GovernorVerdict;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A week of the governor's decision (§4.3, §7).
 *
 * The row §7's daily summary reads for its last, mandatory line: "чего движок
 * делать не стал и почему". An empty week is a valid result of the planner
 * (§4.3) and is indistinguishable from a broken scheduler unless the reason
 * survives the run that decided it, so it is written here rather than logged.
 *
 * @property string $id
 * @property string $project_id
 * @property Carbon $week_start
 * @property int $ceiling
 * @property int $floor
 * @property int $planned
 * @property float|null $reply_rate
 * @property bool $throttled
 * @property int $selection_floor
 * @property string|null $alert
 * @property list<array{code: string, detail: string, at: string}> $reasons
 */
class SocialPlan extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = [
        'week_start',
        'ceiling',
        'floor',
        'planned',
        'reply_rate',
        'throttled',
        'selection_floor',
        'alert',
        'reasons',
    ];

    /**
     * `reasons` defaults in the database, so the instance that created the row
     * has never read it back and holds null where every later instance holds
     * `[]`. Anything that iterates one fatals exactly once — on the run that
     * wrote it, which is the hardest moment to notice.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'reasons' => '[]',
    ];

    /**
     * The week `$at` falls in, for this project, or null if it was never
     * planned.
     *
     * The Monday is computed in the project's timezone because that is the
     * timezone the duty hours and the slots are in. A summary rendered at 01:00
     * in Lisbon must not read Sunday's row.
     */
    public static function forWeek(Project $project, ?CarbonInterface $at = null): ?self
    {
        return static::query()
            ->where('project_id', $project->getKey())
            ->where('week_start', self::weekStart($project, $at)->toDateString())
            ->first();
    }

    public static function weekStart(Project $project, ?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? now())
            ->setTimezone($project->timezone)
            ->startOfWeek();
    }

    /**
     * Record what the governor decided, without touching what the planner did.
     *
     * `updateOrCreate` on (project, week) rather than a fresh row per run: the
     * planner may run twice in a week — by hand after an operator adds duty
     * hours, say — and two rows for one week would give §7 two answers to a
     * question that has one.
     */
    public static function record(Project $project, GovernorVerdict $verdict, int $planned): self
    {
        $week = $verdict->weekStart->toDateString();

        $plan = static::query()
            ->where('project_id', $project->getKey())
            ->where('week_start', $week)
            ->first() ?? new self;

        // Assigned rather than filled: `project_id` is not fillable anywhere in
        // this engine, because the tenant is stamped by {@see BelongsToProject}
        // and a fillable tenant key is how a request body ends up choosing one.
        $plan->setAttribute('project_id', $project->getKey());

        $plan->fill([
            'week_start' => $week,
            'ceiling' => $verdict->weeklyAllowance,
            'floor' => $verdict->weeklyFloor,
            'planned' => $planned,
            'reply_rate' => $verdict->replyRate,
            'throttled' => $verdict->throttled,
            'selection_floor' => $verdict->selectionFloor,
            'alert' => $verdict->alert(),
        ])->save();

        return $plan;
    }

    /**
     * Add one refusal to the week's record.
     *
     * Appended rather than replaced, because refusals arrive from two places at
     * two times: the planner's own run, and `social:kill-expired` later in the
     * week when a reactive draft misses its window (§5). Both are answers to
     * §7's "чего движок делать не стал и почему", so both belong on the same
     * row.
     */
    public function note(string $code, string $detail): self
    {
        $reasons = $this->reasons;
        $reasons[] = ['code' => $code, 'detail' => $detail, 'at' => Carbon::now()->toIso8601String()];

        $this->reasons = $reasons;
        $this->save();

        return $this;
    }

    /** Whether §7's summary has something to shout about this week. */
    public function hasAlert(): bool
    {
        return $this->alert !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'ceiling' => 'integer',
            'floor' => 'integer',
            'planned' => 'integer',
            'reply_rate' => 'float',
            'throttled' => 'boolean',
            'selection_floor' => 'integer',
            'reasons' => 'array',
        ];
    }
}
