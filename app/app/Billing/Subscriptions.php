<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\ProjectUsagePeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes what a project is entitled to.
 *
 * One class rather than methods scattered over a controller, a webhook handler
 * and a console command, because the transitions are the part with rules in
 * them — when the period starts, whether the counters reset, what happens to a
 * trial that is being upgraded — and three copies of those rules is three
 * answers to when somebody's month begins.
 *
 * Nothing here talks to Stripe. Phase 3 puts a provider behind these
 * transitions; this is the vocabulary it will call, and keeping it separate is
 * what lets the whole gating story be tested with no payment provider in it.
 */
class Subscriptions
{
    public function __construct(
        private readonly PlanCatalog $plans,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Start the free window.
     *
     * The clock starts here rather than at registration, and this is called
     * when the engine starts rather than when the account is made: somebody who
     * signs up on Friday and finishes onboarding on Monday has not had a trial
     * over the weekend.
     *
     * Idempotent by construction — a project that already has a subscription
     * keeps it. Relaunching onboarding, or pressing the button twice, must not
     * mint a second trial.
     */
    public function startTrial(Project $project, ?User $payer = null, ?Carbon $at = null): ProjectSubscription
    {
        $existing = $this->find($project);

        if ($existing !== null) {
            return $existing;
        }

        $at ??= Carbon::now();
        $ends = $at->copy()->addDays($this->plans->trialDays());

        $subscription = ProjectSubscription::query()->create([
            'project_id' => $project->getKey(),
            'billing_user_id' => $payer?->getKey(),
            'plan' => 'trial',
            'plan_version' => $this->plans->currentVersion(),
            'status' => BillingStatus::Trialing,
            'limit_overrides' => [],
            'period_started_at' => $at,
            // The trial's period and its trial both end at the same instant,
            // and that is not redundancy: the period is what the counters are
            // keyed to and the trial end is what expiry is judged on. They part
            // company the moment somebody subscribes mid-trial.
            'period_ends_at' => $ends,
            'trial_ends_at' => $ends,
        ]);

        $this->entitlements->forget($project);

        return $subscription;
    }

    /**
     * Put a project on a plan.
     *
     * Used by the console command that assigns plans by hand, by the
     * administrative panel, and — in phase 3 — by the webhook that hears a
     * checkout succeeded.
     *
     * **The counters reset.** Somebody who used their trial's three articles
     * and then paid has bought a month, not the remainder of one, and carrying
     * the trial's usage into it would sell them twenty-seven articles for the
     * price of thirty.
     */
    /**
     * @param  array<string, int|null>  $overrides  limits that differ from the plan's, for this customer
     * @param  int|null  $version  the price list this was sold under; today's when omitted
     */
    public function assign(
        Project $project,
        string $planKey,
        ?User $payer = null,
        ?Carbon $at = null,
        ?Carbon $until = null,
        array $overrides = [],
        ?int $version = null,
    ): ProjectSubscription {
        // Resolved before anything is written, so an unknown plan is a refusal
        // rather than a half-made subscription.
        //
        // The version is passed by the webhook, which reads it back out of the
        // metadata the checkout was stamped with: a session opened under
        // version 1 and completed after version 2 was published bought
        // version 1, and it would be quite a trick to charge somebody for a
        // list that did not exist when they clicked.
        $plan = $this->plans->get($planKey, $version);

        $at ??= Carbon::now();
        $until ??= $at->copy()->addMonth();

        return DB::transaction(function () use ($project, $plan, $payer, $at, $until, $overrides): ProjectSubscription {
            $subscription = $this->find($project);

            $attributes = [
                'plan' => $plan->key,
                'plan_version' => $plan->version,
                'status' => BillingStatus::Active,
                'period_started_at' => $at,
                'period_ends_at' => $until,
                // A plan is not a trial. Leaving the old end date on would make
                // `trialHasExpired()` true for ever after a mid-trial upgrade.
                'trial_ends_at' => null,
                'grace_ends_at' => null,
                'canceled_at' => null,

                // Cleared unless this assignment names its own.
                //
                // Overrides belong to an *arrangement*, not to a project: they
                // are how one Enterprise customer's bespoke numbers are held,
                // and a plan change is the end of that arrangement. Leaving
                // them was the create path and the update path disagreeing —
                // create wrote `[]`, update left them standing — so moving an
                // Enterprise customer down to Small kept every Enterprise
                // limit and silently ignored the plan they had just been put
                // on.
                'limit_overrides' => $overrides,
            ];

            if ($payer !== null) {
                $attributes['billing_user_id'] = $payer->getKey();
            }

            if ($subscription === null) {
                $subscription = ProjectSubscription::query()->create([
                    'project_id' => $project->getKey(),
                    'billing_user_id' => $payer?->getKey(),
                    ...$attributes,
                ]);
            } else {
                $subscription->fill($attributes)->save();
            }

            $this->resetCounters($project);
            $this->entitlements->forget($project);

            return $subscription;
        });
    }

    /**
     * Move the period on without changing the plan.
     *
     * What a renewal is. The counters reset because the month did; the plan,
     * the payer and the overrides all stay exactly as they were.
     */
    public function renew(Project $project, Carbon $from, Carbon $until): ?ProjectSubscription
    {
        $subscription = $this->find($project);

        if ($subscription === null) {
            return null;
        }

        return DB::transaction(function () use ($project, $subscription, $from, $until): ProjectSubscription {
            $subscription->fill([
                'status' => BillingStatus::Active,
                'period_started_at' => $from,
                'period_ends_at' => $until,
                'grace_ends_at' => null,
            ])->save();

            $this->resetCounters($project);
            $this->entitlements->forget($project);

            return $subscription;
        });
    }

    /**
     * A payment failed. Generation stops now; publishing runs to the end of the
     * grace.
     *
     * The grace is not extended by a second failure. Stripe retries a failed
     * invoice several times and each retry is another event; taking the later
     * date each time would make dunning last as long as Stripe kept trying.
     *
     * But a row that is already past due **with no grace on it** is not a
     * second failure — it is a first one that arrived by another route, and it
     * must be given a deadline. Stripe does not order its deliveries, so
     * `customer.subscription.updated` carrying `past_due` can land before the
     * `invoice.payment_failed` that explains it. Skipping on status alone left
     * that project past due for ever with `grace_ends_at` null: nothing to
     * expire, so `mayPublish()` stayed true and the sweep's expiry query — which
     * requires a grace date — never saw it. It published indefinitely on a dead
     * card, which is the single outcome this whole policy exists to bound.
     */
    public function markPastDue(Project $project, ?Carbon $at = null): ?ProjectSubscription
    {
        $subscription = $this->find($project);

        if ($subscription === null) {
            return null;
        }

        if ($subscription->status !== BillingStatus::PastDue || $subscription->grace_ends_at === null) {
            $subscription->fill([
                'status' => BillingStatus::PastDue,
                'grace_ends_at' => ($at ?? Carbon::now())->copy()->addDays($this->plans->graceDays()),
            ])->save();
        }

        $this->entitlements->forget($project);

        return $subscription;
    }

    /**
     * The end.
     *
     * Nothing is deleted and nothing is hidden. `ProjectStatus::Paused` already
     * means "scheduled pipelines skip this project; existing data stays
     * readable", which is exactly the state a lapsed customer should be in, so
     * it is reused rather than duplicated.
     */
    public function cancel(Project $project, ?Carbon $at = null): ?ProjectSubscription
    {
        $subscription = $this->find($project);

        if ($subscription === null) {
            return null;
        }

        $subscription->fill([
            'status' => BillingStatus::Canceled,
            'canceled_at' => $at ?? Carbon::now(),
            'grace_ends_at' => null,
        ])->save();

        $this->entitlements->forget($project);

        return $subscription;
    }

    private function find(Project $project): ?ProjectSubscription
    {
        return ProjectSubscription::query()
            ->where('project_id', $project->getKey())
            ->first();
    }

    /**
     * Counters belong to a period, so a new period simply has none.
     *
     * **All of them, unconditionally.** The first version deleted rows older
     * than the new period start, which quietly assumed the clock had moved
     * between the two — and `period_started_at` is a `timestamp(0)`, so a trial
     * upgraded in the same second as it began compared equal, deleted nothing,
     * and handed the new month the trial's three used articles. Both callers
     * mean the same thing by this — a period begins, nothing carries over — so
     * it says exactly that instead of deducing it from a comparison.
     *
     * The rows are deleted rather than archived because nothing reads them:
     * what a project actually did last month is reconstructable from
     * `content_items` and `pipeline_steps`, which are the truth these counters
     * are only a fast index over.
     */
    private function resetCounters(Project $project): void
    {
        ProjectUsagePeriod::query()
            ->where('project_id', $project->getKey())
            ->delete();
    }
}
