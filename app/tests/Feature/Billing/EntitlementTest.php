<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Entitlement;
use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\Subscriptions;
use App\Enums\BillingStatus;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a project may do, and the four ways the answer can be no.
 *
 * This is the phase with the risk in it. If entitlement is wrong, adding a
 * payment provider only makes it wrong with money attached — which is why the
 * whole gating story is built and exercised with no Stripe in it at all.
 */
final class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Unbilled by default here, because every test below says out loud
        // which subscription it is about — including the one that says there
        // is none.
        $this->project = Project::factory()->unbilled()->create();
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function an_excluded_allowance_does_not_show_as_used_up_but_real_article_exhaustion_does(): void
    {
        // Starter includes no page improvements: a limit of zero is a feature
        // the plan leaves out, not one the manager has used up.
        ProjectSubscription::factory()->forProject($this->project)->plan('starter')->create();
        $entitlements = app(Entitlements::class);
        $entitlements->forget($this->project);
        $this->assertNotContains(Metric::PageImprovements->value, $entitlements->for($this->project)->exhausted());
        $entitlements->record($this->project, Metric::Articles, 12);
        $entitlements->forget($this->project);
        $this->assertContains(Metric::Articles->value, $entitlements->for($this->project)->exhausted());
        $this->assertNotContains(Metric::PageImprovements->value, $entitlements->for($this->project)->exhausted());
    }

    #[Test]
    public function a_project_with_no_subscription_may_do_nothing(): void
    {
        $entitlement = $this->entitlement();

        $this->assertFalse($entitlement->mayGenerate());
        $this->assertFalse($entitlement->mayPublish());
        $this->assertSame('no_subscription', $entitlement->refusal()?->code);
    }

    #[Test]
    public function a_paying_project_may_work(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->create();

        $this->assertTrue($this->entitlement()->mayGenerate());
        $this->assertTrue($this->entitlement()->mayPublish());
    }

    #[Test]
    public function a_trial_that_has_run_out_is_refused_on_the_dates_alone(): void
    {
        // No sweep has run. Entitlement is decided by reading, so an expired
        // trial stops the engine at the instant it expires rather than at the
        // instant a scheduled command notices — a stopped scheduler must not
        // hand out free service.
        ProjectSubscription::factory()->forProject($this->project)->trialExpired()->create();

        $entitlement = $this->entitlement();

        $this->assertSame(BillingStatus::Trialing, $entitlement->status);
        $this->assertFalse($entitlement->mayGenerate());
        $this->assertSame('trial_ended', $entitlement->refusal()?->code);
    }

    #[Test]
    public function a_failed_payment_stops_generating_and_keeps_publishing(): void
    {
        // The whole of the dunning policy in one assertion pair. We stop
        // spending our money at once; we stop delivering theirs at the end.
        ProjectSubscription::factory()->forProject($this->project)->pastDue()->create();

        $this->assertFalse($this->entitlement()->mayGenerate());
        $this->assertTrue($this->entitlement()->mayPublish());
        $this->assertSame('past_due', $this->entitlement()->refusal()?->code);
    }

    #[Test]
    public function a_cancelled_project_neither_generates_nor_publishes(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->canceled()->create();

        $this->assertFalse($this->entitlement()->mayGenerate());
        $this->assertFalse($this->entitlement()->mayPublish());
    }

    #[Test]
    public function a_used_up_quota_refuses_only_the_thing_that_ran_out(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('starter')->create();

        $entitlements = app(Entitlements::class);
        $entitlements->record($this->project, Metric::Articles, 12);

        $entitlement = $this->entitlement();

        $this->assertSame(0, $entitlement->remaining(Metric::Articles));
        $this->assertSame('quota', $entitlement->refusal(Metric::Articles)?->code);

        // And nothing else. A project out of articles can still ask the
        // assistant, and refusing everything because one counter filled would
        // be a pause dressed up as a limit.
        $this->assertNull($entitlement->refusal(Metric::AssistantTurns));
        $this->assertNull($entitlement->refusal());
    }

    #[Test]
    public function an_unlimited_allowance_is_never_confused_with_a_used_up_one(): void
    {
        // `null` means unlimited everywhere and must never read as zero — the
        // failure that would silently forbid whatever a new plan forgot to
        // name.
        ProjectSubscription::factory()->forProject($this->project)->plan('starter')->create([
            'limit_overrides' => ['articles' => null],
        ]);

        $entitlements = app(Entitlements::class);
        $entitlements->record($this->project, Metric::Articles, 5_000);

        $this->assertNull($this->entitlement()->remaining(Metric::Articles));
        $this->assertTrue($this->entitlement()->hasRoomFor(Metric::Articles, 900));
    }

    #[Test]
    public function the_cost_ceiling_trips_before_the_quota_does(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('starter')->create();

        // Starter's ceiling is $25. A project that has burnt three times its
        // plan's cost while still inside its article count has a problem the
        // article count cannot describe, and "you have used your articles"
        // would send somebody to buy more of what is going wrong.
        $run = PipelineRun::factory()->for($this->project)->create();
        PipelineStep::factory()->for($run, 'pipelineRun')->create(['cost_micros' => 25_000_000]);

        $entitlement = $this->entitlement();

        $this->assertFalse($entitlement->mayGenerate());
        $this->assertSame('cost_ceiling', $entitlement->refusal(Metric::Articles)?->code);
        // Still has articles left. The two layers answer different questions.
        $this->assertSame(12, $entitlement->remaining(Metric::Articles));
    }

    #[Test]
    public function the_cost_ceiling_is_never_shown_to_the_customer(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('starter')->create();

        $props = $this->entitlement()->toArray();

        // A progress bar towards a number nobody was sold reads as a catch.
        $this->assertArrayNotHasKey('cost_micros', $props['usage']);
        $this->assertArrayNotHasKey('cost_ceiling', $props);
    }

    #[Test]
    public function the_cadence_is_clamped_on_read_and_never_written_back(): void
    {
        $this->project->forceFill(['weekly_target' => 14])->save();
        ProjectSubscription::factory()->forProject($this->project)->plan('starter')->create();

        // Starter clamps to 3.
        $this->assertSame(3, $this->project->weeklyTarget());
        // And the stored column is untouched, so upgrading gives 14 back
        // rather than the number a plan quietly overwrote it with.
        $this->assertSame(14, $this->project->fresh()?->weekly_target);
    }

    #[Test]
    public function a_plan_never_raises_a_cadence_the_operator_set_lower(): void
    {
        $this->project->forceFill(['weekly_target' => 1])->save();
        ProjectSubscription::factory()->forProject($this->project)->plan('growth')->create();

        // Growth's ceiling is 7. It is a ceiling, not a target.
        $this->assertSame(1, $this->project->weeklyTarget());
    }

    #[Test]
    public function usage_is_counted_atomically(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('growth')->create();

        $entitlements = app(Entitlements::class);

        foreach (range(1, 5) as $ignored) {
            $entitlements->record($this->project, Metric::Articles);
        }

        $this->assertSame(5, $this->entitlement()->used(Metric::Articles));
        $this->assertSame(25, $this->entitlement()->remaining(Metric::Articles));
    }

    #[Test]
    public function counters_belong_to_a_period_and_a_new_one_starts_empty(): void
    {
        $subscriptions = app(Subscriptions::class);
        $subscriptions->startTrial($this->project);

        app(Entitlements::class)->record($this->project, Metric::Articles, 3);
        $this->assertSame(3, $this->entitlement()->used(Metric::Articles));

        // Somebody who used their trial's articles and then paid has bought a
        // month, not the remainder of one.
        $subscriptions->assign($this->project, 'growth');

        $this->assertSame(0, $this->entitlement()->used(Metric::Articles));
    }

    #[Test]
    public function a_trial_is_started_once_however_many_times_it_is_asked_for(): void
    {
        $subscriptions = app(Subscriptions::class);

        $first = $subscriptions->startTrial($this->project);
        $second = $subscriptions->startTrial($this->project);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ProjectSubscription::query()->count());
    }

    #[Test]
    public function upgrading_mid_trial_clears_the_trial_end(): void
    {
        $subscriptions = app(Subscriptions::class);
        $subscriptions->startTrial($this->project);
        $subscription = $subscriptions->assign($this->project, 'growth');

        // Left standing, `trialHasExpired()` would become true for ever three
        // days after a customer started paying.
        $this->assertNull($subscription->trial_ends_at);
        $this->assertSame(BillingStatus::Active, $subscription->status);
        $this->assertTrue($this->entitlement()->mayGenerate());
    }

    #[Test]
    public function a_second_failed_payment_does_not_extend_the_grace(): void
    {
        $subscriptions = app(Subscriptions::class);
        ProjectSubscription::factory()->forProject($this->project)->create();

        $first = $subscriptions->markPastDue($this->project, now());
        $ends = $first?->grace_ends_at;

        // Stripe retries a failed invoice several times and each retry is
        // another event; taking the later date each time would make dunning
        // last as long as Stripe kept trying.
        $second = $subscriptions->markPastDue($this->project, now()->addDays(3));

        $this->assertTrue($ends?->equalTo($second?->grace_ends_at));
    }

    #[Test]
    public function an_unknown_plan_is_refused_rather_than_guessed(): void
    {
        // Defaulting up gives the product away and defaulting down locks a
        // paying customer out, and both would be silent.
        $this->expectException(InvalidArgumentException::class);

        app(PlanCatalog::class)->get('platinum');
    }

    #[Test]
    public function overrides_widen_one_customer_without_touching_the_plan(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('starter')->create([
            'limit_overrides' => ['articles' => 250],
        ]);

        $this->assertSame(250, $this->entitlement()->limit('articles'));
        // Merged rather than replacing the set, so a bespoke article count does
        // not make every unnamed limit unlimited.
        $this->assertSame(100, $this->entitlement()->limit('assistant_turns'));
        $this->assertSame(12, app(PlanCatalog::class)->get('starter')->limit('articles'));
    }

    #[Test]
    public function a_month_that_ended_rolls_over_rather_than_running_for_ever(): void
    {
        // Nothing advanced a period before this, and the omission was silent in
        // the worst way. A period is what the unit counters are keyed to *and*
        // the window the cost ceiling sums spend across — so a subscription
        // whose month never turns exhausts its quotas once and never resets,
        // and accumulates spend against a one-month fuse indefinitely.
        $subscription = ProjectSubscription::factory()->forProject($this->project)->create([
            'period_started_at' => now()->subMonths(2),
            'period_ends_at' => now()->subMonth(),
        ]);

        app(Entitlements::class)->record($this->project, Metric::Articles, 30);
        $this->assertSame(0, $this->entitlement()->remaining(Metric::Articles));

        $this->sweep()->assertSuccessful();

        $this->assertSame(30, $this->entitlement()->remaining(Metric::Articles));
        $this->assertTrue($subscription->fresh()?->period_ends_at?->isFuture());
    }

    #[Test]
    public function a_rolled_period_starts_where_the_last_one_ended(): void
    {
        $ended = now()->subDays(2)->startOfDay();

        ProjectSubscription::factory()->forProject($this->project)->create([
            'period_started_at' => $ended->copy()->subMonth(),
            'period_ends_at' => $ended,
        ]);

        $this->sweep()->assertSuccessful();

        // From the end of the last period, not from now — a sweep that did not
        // run for two days must not shorten the month it is catching up on.
        $this->assertTrue(
            ProjectSubscription::query()->sole()->period_started_at?->equalTo($ended),
        );
    }

    #[Test]
    public function a_provider_backed_period_is_left_for_the_provider_to_move(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->create([
            'stripe_id' => 'sub_test',
            'period_started_at' => now()->subMonths(2),
            'period_ends_at' => now()->subMonth(),
        ]);

        $this->sweep()->assertSuccessful();

        // Where Stripe is the source of truth, the new window arrives on
        // `customer.subscription.updated` with real dates on it. Inventing one
        // here would put us out of step with the thing being paid.
        $this->assertTrue(ProjectSubscription::query()->sole()->period_ends_at?->isPast());
    }

    #[Test]
    public function publishing_stops_when_the_grace_runs_out_and_not_when_the_sweep_notices(): void
    {
        // Read from the dates, like the trial is. If delivery waited for
        // `billing:sweep` to flip the column, a stopped scheduler would keep
        // publishing for somebody whose dunning ended a week ago.
        ProjectSubscription::factory()->forProject($this->project)->pastDue()->create([
            'grace_ends_at' => now()->subDay(),
        ]);

        $entitlement = $this->entitlement();

        $this->assertFalse($entitlement->mayGenerate());
        $this->assertFalse($entitlement->mayPublish());
    }

    #[Test]
    public function publishing_stops_when_a_trial_runs_out_too(): void
    {
        // The grace hole was closed first and this one left open, which is the
        // larger of the two: every project starts on a trial and only some ever
        // reach dunning.
        ProjectSubscription::factory()->forProject($this->project)->trialExpired()->create();

        $this->assertFalse($this->entitlement()->mayGenerate());
        $this->assertFalse($this->entitlement()->mayPublish());
    }

    #[Test]
    public function publishing_continues_while_a_trial_still_has_time(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialing()->create();

        $this->assertTrue($this->entitlement()->mayGenerate());
        $this->assertTrue($this->entitlement()->mayPublish());
    }

    #[Test]
    public function publishing_continues_while_the_grace_still_has_time(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->pastDue()->create();

        $this->assertFalse($this->entitlement()->mayGenerate());
        $this->assertTrue($this->entitlement()->mayPublish());
    }

    #[Test]
    public function moving_to_another_plan_does_not_keep_the_last_ones_bespoke_limits(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('growth')->create([
            'limit_overrides' => ['articles' => 5_000],
        ]);

        app(Subscriptions::class)->assign($this->project, 'starter');

        // Overrides belong to an arrangement, not to a project, and a plan
        // change ends the arrangement. Kept, they silently overrode the plan
        // the customer had just been moved on to.
        $this->assertSame(12, $this->entitlement()->limit('articles'));
        $this->assertSame([], ProjectSubscription::query()->sole()->limit_overrides);
    }

    #[Test]
    public function an_arrangement_can_still_name_its_own_limits(): void
    {
        app(Subscriptions::class)->assign(
            $this->project,
            'growth',
            overrides: ['articles' => 5_000],
        );

        $this->assertSame(5_000, $this->entitlement()->limit('articles'));
    }

    #[Test]
    public function a_stripe_trial_is_bounded_by_the_trials_caps_and_not_the_plans(): void
    {
        // A public trial is a *paid plan with free days on the front*: the
        // checkout stamps `growth` and the subscription arrives as
        // `plan = growth, status = trialing`. Reading limits from the purchased
        // plan gave every trial Growth's thirty articles, a hundred assistant
        // turns and — the one that costs us — a seventy-five-dollar ceiling in
        // place of five.
        ProjectSubscription::factory()->forProject($this->project)->plan('growth')->create([
            'status' => BillingStatus::Trialing,
            'trial_ends_at' => now()->addDays(2),
        ]);

        $entitlement = $this->entitlement();

        $this->assertSame(3, $entitlement->limit('articles'));
        $this->assertSame(20, $entitlement->limit('assistant_turns'));
        $this->assertSame(5_000_000, $entitlement->limit('cost_micros'));

        // And the plan they bought is still what they are told they are on.
        $this->assertSame('Growth', $entitlement->plan->name);
        $this->assertSame(8_900, $entitlement->plan->priceCents);
    }

    #[Test]
    public function the_purchased_plans_limits_apply_the_moment_the_trial_converts(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('growth')->create([
            'status' => BillingStatus::Active,
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->assertSame(30, $this->entitlement()->limit('articles'));
    }

    #[Test]
    public function a_bespoke_arrangement_survives_its_own_free_window(): void
    {
        // An arrangement somebody negotiated does not stop applying because
        // the first three days are free.
        ProjectSubscription::factory()->forProject($this->project)->plan('growth')->create([
            'status' => BillingStatus::Trialing,
            'trial_ends_at' => now()->addDays(2),
            'limit_overrides' => ['articles' => 400],
        ]);

        $this->assertSame(400, $this->entitlement()->limit('articles'));
        // Everything it did not name still comes from the trial.
        $this->assertSame(20, $this->entitlement()->limit('assistant_turns'));
    }

    #[Test]
    public function a_trial_is_opened_on_the_trials_own_limits(): void
    {
        // The trial is a plan like any other, so a started trial names it and
        // reads its caps rather than those of whatever it may turn into.
        app(Subscriptions::class)->startTrial($this->project);

        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame('trial', $subscription->plan);
        $this->assertSame(3, $this->entitlement()->limit('articles'));
    }

    #[Test]
    public function the_trial_is_not_something_anybody_can_buy(): void
    {
        $keys = array_map(
            static fn (Plan $plan): string => $plan->key,
            app(PlanCatalog::class)->selfServe(),
        );

        $this->assertSame(['starter', 'growth'], $keys);
    }

    /**
     * `artisan()` is declared as returning `PendingCommand|int`, so without
     * this every call site would repeat the same annotation.
     */
    private function sweep(): PendingCommand
    {
        /** @var PendingCommand $pending */
        $pending = $this->artisan('billing:sweep');

        return $pending;
    }

    private function entitlement(): Entitlement
    {
        $entitlements = app(Entitlements::class);
        $entitlements->forget($this->project);

        return $entitlements->for($this->project);
    }
}
