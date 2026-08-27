<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\ProjectUsagePeriod;
use App\Support\Metering\ProjectSpend;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one place that answers what a project may do.
 *
 * Registered as a singleton and memoised per project, because within one
 * request the answer is consulted several times — route middleware, then the
 * shared props the layout renders from, then whatever the controller does —
 * and three reads that could disagree is three chances to gate a route open
 * and paint it shut.
 *
 * The memo is by project id rather than a single slot: `engine:tick` walks
 * every project in one process, and a one-slot cache there would answer for
 * whichever project asked first.
 */
class Entitlements
{
    /** @var array<string, Entitlement> */
    private array $memo = [];

    public function for(Project $project): Entitlement
    {
        return $this->memo[$project->getKey()] ??= $this->resolve($project);
    }

    /**
     * Forget what was decided, for the callers that just changed it.
     *
     * Console commands that assign a plan and then act on it, and tests. A
     * request never needs this: nothing inside one both changes a subscription
     * and re-reads it.
     */
    public function forget(?Project $project = null): void
    {
        if ($project === null) {
            $this->memo = [];

            return;
        }

        unset($this->memo[$project->getKey()]);
    }

    /**
     * Count something against this period's quota.
     *
     * An upsert rather than a read-modify-write, so two requests approving an
     * article at the same instant produce two counts. The unique index on
     * (project, period, metric) is what gives the statement one row to conflict
     * with; without it this would race exactly as badly as the version it
     * replaces.
     *
     * Recording is separate from checking on purpose. The check happens before
     * the work — a refusal must cost nothing — and the count happens when the
     * work is real, which for content is approval rather than generation: the
     * engine writes eight social posts to keep one, and charging a customer for
     * the seven it discarded would make the number on their screen meaningless.
     * The seven are not free, and they are what the cost ceiling is watching.
     */
    public function record(Project $project, Metric $metric, int $by = 1): void
    {
        if ($by <= 0) {
            return;
        }

        $subscription = $this->subscriptionFor($project);

        if ($subscription === null) {
            return;
        }

        $period = $subscription->periodStart();

        DB::table('project_usage_periods')->upsert(
            [[
                'id' => (string) Str::ulid(),
                'project_id' => $project->getKey(),
                'period_started_at' => $period,
                'metric' => $metric->value,
                'used' => $by,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]],
            ['project_id', 'period_started_at', 'metric'],
            ['used' => DB::raw('project_usage_periods.used + excluded.used'), 'updated_at' => DB::raw('excluded.updated_at')],
        );

        $this->forget($project);
    }

    private function resolve(Project $project): Entitlement
    {
        $subscription = $this->subscriptionFor($project);

        if ($subscription === null) {
            return Entitlement::none();
        }

        $period = $subscription->periodStart();

        return new Entitlement(
            subscription: $subscription,
            plan: $subscription->plan(),
            status: $subscription->status,
            usage: $this->usage($project, $period),
            // Both doors. The pipelines' spend and the conversation's, which is
            // the only sum that answers what this project has cost us — see
            // ProjectSpend for why there is one of it.
            spentMicros: ProjectSpend::total($project, $period),
            periodEndsAt: $subscription->period_ends_at,
            trialEndsAt: $subscription->trial_ends_at,
        );
    }

    private function subscriptionFor(Project $project): ?ProjectSubscription
    {
        return ProjectSubscription::query()
            ->where('project_id', $project->getKey())
            ->first();
    }

    /** @return array<string, int> */
    private function usage(Project $project, CarbonInterface $period): array
    {
        return ProjectUsagePeriod::query()
            ->where('project_id', $project->getKey())
            ->where('period_started_at', $period)
            ->pluck('used', 'metric')
            ->map(static fn (mixed $used): int => (int) $used)
            ->all();
    }
}
