<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Entitlements;
use App\Billing\PlanCatalog;
use App\Billing\Subscriptions;
use App\Enums\BillingStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Metering\ProjectSpend;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every project, and what can be done to one.
 *
 * Every read here opts into `acrossProjects()` or names the project
 * explicitly, because {@see ProjectScope} fails closed —
 * which is the property that makes this safe to write. A query somebody forgot
 * to widen shows an empty table rather than another tenant's rows: the failure
 * mode is a visible bug, not a leak.
 *
 * Every mutation writes an {@see AdminAction}. Six months from now, "why is
 * this account on Enterprise" has to have an answer that is not a guess.
 */
class AdminProjectController extends Controller
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly PlanCatalog $plans,
        private readonly Entitlements $entitlements,
        private readonly CurrentProject $current,
        private readonly BillingProvider $provider,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $since = Carbon::now()->startOfMonth();

        $projects = Project::query()
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%")
                    ->orWhere('website_url', 'ilike', "%{$search}%"),
            ))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $subscriptions = ProjectSubscription::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->get()
            ->keyBy('project_id');

        // Batched for the same reason the overview batches it: one page of
        // twenty-five would otherwise be fifty extra round trips.
        /** @var list<string> $ids */
        $ids = $projects->pluck('id')->values()->all();

        $spends = ProjectSpend::totals($ids, $since);

        return Inertia::render('admin/projects', [
            'q' => $search,
            'currency' => (string) config('billing.currency', 'eur'),
            'projects' => $projects->through(function (Project $project) use ($subscriptions, $spends): array {
                $subscription = $subscriptions->get($project->getKey());

                return [
                    'id' => $project->getKey(),
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'website_url' => $project->website_url,
                    'status' => $project->status->value,
                    'plan' => $subscription?->plan()->name,
                    'billing_status' => $subscription?->status->value,
                    'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
                    'price_cents' => $subscription?->plan()->priceCents ?? 0,
                    'cost_micros' => $spends[$project->getKey()] ?? 0,
                ];
            }),
        ]);
    }

    public function show(Project $project): Response
    {
        $since = Carbon::now()->startOfMonth();

        // The entitlement is read *as the project*, because everything on it —
        // counters, spend, the plan — is scoped to one tenant and this
        // controller is standing outside all of them.
        $entitlement = $this->current->run($project, fn () => $this->entitlements->for($project)->toArray());

        $subscription = ProjectSubscription::query()->where('project_id', $project->getKey())->first();

        return Inertia::render('admin/project', [
            'project' => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'slug' => $project->slug,
                'website_url' => $project->website_url,
                'status' => $project->status->value,
                'weekly_target' => $project->weekly_target,
                'locales' => $project->locales,
                'created_at' => $project->created_at?->toIso8601String(),
            ],
            'entitlement' => $entitlement,
            'subscription' => $subscription === null ? null : [
                'plan' => $subscription->plan,
                'plan_version' => $subscription->plan_version,
                'status' => $subscription->status->value,
                'limit_overrides' => $subscription->limit_overrides,
                'period_started_at' => $subscription->period_started_at?->toIso8601String(),
                'period_ends_at' => $subscription->period_ends_at?->toIso8601String(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
                'stripe_id' => $subscription->stripe_id,
                'stripe_status' => $subscription->stripe_status,
                'payer' => $subscription->payer?->email,
            ],
            'spend' => ProjectSpend::for($project, $since)->toArray(),
            'currency' => (string) config('billing.currency', 'eur'),
            'plans' => array_map(
                fn ($plan): array => ['key' => $plan->key, 'name' => $plan->name, 'price_cents' => $plan->priceCents],
                $this->plans->all(),
            ),
            'members' => $project->users()->get()->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getAttribute('pivot')?->getAttribute('role'),
            ])->all(),
            'actions' => AdminAction::query()
                ->with('actor')
                ->where('project_id', $project->getKey())
                ->latest('created_at')
                ->limit(25)
                ->get()
                ->map(fn (AdminAction $action): array => [
                    'id' => $action->id,
                    'action' => $action->action,
                    // Explicit rather than `?->name ?? …`. The relation really
                    // is nullable — `user_id` is `nullOnDelete`, because the
                    // record that a subscription was comped must outlive the
                    // account that comped it — but analysis reads a `BelongsTo`
                    // as always present, so the null-safe form gets flagged as
                    // dead. This says the same thing in a shape it can see.
                    'actor' => $action->actor instanceof User
                        ? $action->actor->name
                        : 'a deleted account',
                    'before' => $action->before,
                    'after' => $action->after,
                    'at' => $action->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /** Put a project on a plan, or comp one. */
    public function assign(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string'],
            // Enterprise is a shape here and numbers on the row. Optional
            // because most assignments are an ordinary plan with no bespoke
            // anything.
            'overrides' => ['sometimes', 'array'],
            'overrides.*' => ['nullable', 'integer', 'min:0'],
        ]);

        abort_unless($this->plans->has((string) $validated['plan']), 422);

        $plan = $this->plans->get((string) $validated['plan']);
        $before = $this->snapshot($project);

        /** @var array<string, int|null> $overrides */
        $overrides = $validated['overrides'] ?? [];

        $current = ProjectSubscription::query()->where('project_id', $project->getKey())->first();
        $atProvider = $current !== null && $current->stripe_id !== null;

        // Where Stripe is charging for it, Stripe has to be told.
        //
        // Changing only `project_subscriptions` would leave the customer paying
        // one tier and receiving another — and the next
        // `customer.subscription.updated` would read the unchanged metadata and
        // put the local entitlement back on the old plan, so the change would
        // also quietly undo itself.
        if ($atProvider) {
            $payer = $current->payer;

            if (! $payer instanceof User || ! $this->provider->changePlan($payer, $project, $plan)) {
                // Refused rather than half-done. A local row that disagrees
                // with what is being charged is worse than a button that says
                // it could not do the thing.
                throw ValidationException::withMessages([
                    'plan' => 'This subscription is billed by Stripe and the change could not be made there. Nothing has changed.',
                ]);
            }
        }

        // The billing window belongs to whatever is charging for it, so a
        // provider-backed subscription keeps the one Stripe named — otherwise
        // `billing:reconcile` would roll it back that night and reset the
        // counters a second time.
        $this->subscriptions->assign(
            $project,
            $plan->key,
            at: $atProvider ? $current->period_started_at : null,
            until: $atProvider ? $current->period_ends_at : null,
            overrides: $overrides,
        );

        return $this->recorded($request, 'plan.assigned', $project, $before);
    }

    /** Give somebody more of the free window. */
    public function extendTrial(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        $subscription = ProjectSubscription::query()->where('project_id', $project->getKey())->firstOrFail();
        $before = $this->snapshot($project);

        // From the later of now and the current end, so extending a trial that
        // already lapsed gives the days asked for rather than back-dating them
        // into a window that has closed.
        $from = $subscription->trial_ends_at?->isFuture() === true
            ? $subscription->trial_ends_at
            : Carbon::now();

        $ends = $from->copy()->addDays((int) $validated['days']);

        // Stripe owns the date it will invoice on. Extending only our copy
        // gives somebody a free window we believe in and Stripe does not — they
        // are charged on the original date, inside the days we just promised
        // them, and the next webhook overwrites the local `trialing` state so
        // the extension disappears as well.
        if ($subscription->stripe_id !== null) {
            $payer = $subscription->payer;

            if (! $payer instanceof User || ! $this->provider->extendTrial($payer, $project, $ends)) {
                throw ValidationException::withMessages([
                    'days' => 'This trial is held by Stripe and could not be extended there. Nothing has changed.',
                ]);
            }
        }

        $subscription->fill([
            'status' => BillingStatus::Trialing,
            'trial_ends_at' => $ends,
            'period_ends_at' => $ends,
            'grace_ends_at' => null,
            'canceled_at' => null,
        ])->save();

        // And start the engine again, because `billing:sweep` is what paused
        // it. Extending a lapsed trial and leaving the project paused gives
        // somebody their days back and no engine to spend them on — the
        // administrator would have to notice and press a second control, and
        // the one they pressed would appear to have done nothing.
        if ($project->status !== ProjectStatus::Active) {
            $project->forceFill(['status' => ProjectStatus::Active])->save();
        }

        $this->entitlements->forget($project);

        return $this->recorded($request, 'trial.extended', $project, $before);
    }

    /** Stop, or start again. */
    public function status(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,paused'],
        ]);

        $before = $this->snapshot($project);

        $project->forceFill([
            'status' => ProjectStatus::from((string) $validated['status']),
        ])->save();

        return $this->recorded($request, 'project.status', $project, $before);
    }

    /**
     * What the row looked like, for the log.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Project $project): array
    {
        $subscription = ProjectSubscription::query()->where('project_id', $project->getKey())->first();

        return [
            'project_status' => $project->status->value,
            'plan' => $subscription?->plan,
            'plan_version' => $subscription?->plan_version,
            'billing_status' => $subscription?->status->value,
            'limit_overrides' => $subscription?->limit_overrides,
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
            'period_ends_at' => $subscription?->period_ends_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function recorded(Request $request, string $action, Project $project, array $before): RedirectResponse
    {
        $user = $request->user();

        AdminAction::record(
            $user instanceof User ? $user : null,
            $action,
            $project,
            $before,
            $this->snapshot($project->refresh()),
        );

        return back();
    }
}
