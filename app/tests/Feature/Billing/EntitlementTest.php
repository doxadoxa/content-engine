<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Entitlement;
use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Billing\PlanCatalog;
use App\Billing\Subscriptions;
use App\Enums\BillingStatus;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ProjectSubscription::factory()->forProject($this->project)->plan('small')->create();

        $entitlements = app(Entitlements::class);
        $entitlements->record($this->project, Metric::Articles, 10);

        $entitlement = $this->entitlement();

        $this->assertSame(0, $entitlement->remaining(Metric::Articles));
        $this->assertSame('quota', $entitlement->refusal(Metric::Articles)?->code);

        // And nothing else. A project out of articles can still cut a social
        // post, and refusing everything because one counter filled would be a
        // pause dressed up as a limit.
        $this->assertNull($entitlement->refusal(Metric::SocialPosts));
        $this->assertNull($entitlement->refusal());
    }

    #[Test]
    public function an_unlimited_allowance_is_never_confused_with_a_used_up_one(): void
    {
        // `null` means unlimited everywhere and must never read as zero — the
        // failure that would silently forbid whatever a new plan forgot to
        // name.
        ProjectSubscription::factory()->forProject($this->project)->plan('enterprise')->create();

        $entitlements = app(Entitlements::class);
        $entitlements->record($this->project, Metric::Articles, 5_000);

        $this->assertNull($this->entitlement()->remaining(Metric::Articles));
        $this->assertTrue($this->entitlement()->hasRoomFor(Metric::Articles, 900));
    }

    #[Test]
    public function the_cost_ceiling_trips_before_the_quota_does(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('small')->create();

        // Small's ceiling is $20. A project that has burnt three times its
        // plan's cost while still inside its article count has a problem the
        // article count cannot describe, and "you have used your articles"
        // would send somebody to buy more of what is going wrong.
        $run = PipelineRun::factory()->for($this->project)->create();
        PipelineStep::factory()->for($run, 'pipelineRun')->create(['cost_micros' => 25_000_000]);

        $entitlement = $this->entitlement();

        $this->assertFalse($entitlement->mayGenerate());
        $this->assertSame('cost_ceiling', $entitlement->refusal(Metric::Articles)?->code);
        // Still has articles left. The two layers answer different questions.
        $this->assertSame(10, $entitlement->remaining(Metric::Articles));
    }

    #[Test]
    public function the_cost_ceiling_is_never_shown_to_the_customer(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('small')->create();

        $props = $this->entitlement()->toArray();

        // A progress bar towards a number nobody was sold reads as a catch.
        $this->assertArrayNotHasKey('cost_micros', $props['usage']);
        $this->assertArrayNotHasKey('cost_ceiling', $props);
    }

    #[Test]
    public function the_cadence_is_clamped_on_read_and_never_written_back(): void
    {
        $this->project->forceFill(['weekly_target' => 14])->save();
        ProjectSubscription::factory()->forProject($this->project)->plan('small')->create();

        // Small clamps to 2.
        $this->assertSame(2, $this->project->weeklyTarget());
        // And the stored column is untouched, so upgrading gives 14 back
        // rather than the number a plan quietly overwrote it with.
        $this->assertSame(14, $this->project->fresh()?->weekly_target);
    }

    #[Test]
    public function a_plan_never_raises_a_cadence_the_operator_set_lower(): void
    {
        $this->project->forceFill(['weekly_target' => 1])->save();
        ProjectSubscription::factory()->forProject($this->project)->plan('medium')->create();

        // Medium's ceiling is 7. It is a ceiling, not a target.
        $this->assertSame(1, $this->project->weeklyTarget());
    }

    #[Test]
    public function usage_is_counted_atomically(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('medium')->create();

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
        $subscriptions->assign($this->project, 'medium');

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
        $subscription = $subscriptions->assign($this->project, 'medium');

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
    public function a_project_keeps_the_plan_version_it_was_sold(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->create([
            'plan' => 'small',
            'plan_version' => 1,
        ]);

        // Whatever the current version becomes, the row names the one it was
        // opened under — re-pricing must not change what somebody already
        // paying is allowed to do.
        $this->assertSame(1, $this->entitlement()->plan?->version);
        $this->assertSame(10, $this->entitlement()->limit('articles'));
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
        ProjectSubscription::factory()->forProject($this->project)->plan('small')->create([
            'limit_overrides' => ['articles' => 250],
        ]);

        $this->assertSame(250, $this->entitlement()->limit('articles'));
        // Merged rather than replacing the set, so a bespoke article count does
        // not make every unnamed limit unlimited.
        $this->assertSame(10, $this->entitlement()->limit('social_posts'));
        $this->assertSame(10, app(PlanCatalog::class)->get('small')->limit('articles'));
    }

    private function entitlement(): Entitlement
    {
        $entitlements = app(Entitlements::class);
        $entitlements->forget($this->project);

        return $entitlements->for($this->project);
    }
}
