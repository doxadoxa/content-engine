<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Entitlement;
use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Enums\OnboardingStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the banner is told, so that what it says is right.
 *
 * A paying customer on the Growth trial was shown "Your trial ends in 3 days"
 * beside "Choose a plan" — a plan they had chosen — and later an amber
 * triangle saying this period's content plans were used up, which is what the
 * engine is supposed to do once a period and reads as an error. The rules for
 * both live on the server so they can be tested here; the banner only renders
 * `converts_after_trial` and `shortfalls`.
 */
final class BillingBannerPropsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->project = Project::factory()->unbilled()->create([
            'status' => ProjectStatus::Active,
            'onboarding_status' => OnboardingStatus::Active,
        ]);

        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function a_trial_of_a_chosen_plan_converts_and_its_sample_allowance_is_not_a_shortfall(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialing()->plan('growth')->create(['stripe_id' => 'sub_trial']);

        // The whole trial allowance, used the way a first run uses it.
        $this->record(Metric::Articles, 3);
        $this->record(Metric::ContentPlans, 1);
        $this->record(Metric::SiteAudits, 1);
        $this->record(Metric::AiAnswers, 12);

        $billing = $this->entitlement()->toArray();

        $this->assertTrue($billing['converts_after_trial']);
        // Still the true list, for anything that needs it…
        $this->assertEqualsCanonicalizing(
            ['articles', 'content_plans', 'site_audits', 'ai_answers'],
            $billing['exhausted'],
        );
        // …but nothing worth a warning: the sample is sample-sized on purpose.
        $this->assertSame([], $billing['shortfalls']);
    }

    #[Test]
    public function the_legacy_bare_trial_has_not_chosen_a_plan_so_does_not_convert(): void
    {
        // `Subscriptions::startTrial()` names the plan `trial`: nobody picked
        // one, so the countdown to choosing is still the right thing to show.
        ProjectSubscription::factory()->forProject($this->project)->trialing()->create();

        $this->assertFalse($this->entitlement()->toArray()['converts_after_trial']);
    }

    #[Test]
    public function a_trial_cancelled_before_it_ends_does_not_convert(): void
    {
        // Still `trialing` until the date — Stripe stamps `canceled_at` on a
        // cancel-at-period-end — but nothing will start afterwards, and the
        // banner must not promise it will.
        ProjectSubscription::factory()->forProject($this->project)->trialing()->plan('growth')->create([
            'stripe_id' => 'sub_trial',
            'canceled_at' => Carbon::now()->subHour(),
        ]);

        $billing = $this->entitlement()->toArray();

        $this->assertSame('trialing', $billing['status']);
        $this->assertFalse($billing['converts_after_trial']);
    }

    #[Test]
    public function a_trial_no_stripe_subscription_holds_does_not_convert(): void
    {
        // An administrator put a locally assigned Growth back into a trial.
        // There is no card and nothing at Stripe to charge it, so the banner
        // must not promise that a plan starts on the end date.
        ProjectSubscription::factory()->forProject($this->project)->trialing()->plan('growth')->create();

        $this->assertFalse($this->entitlement()->toArray()['converts_after_trial']);
    }

    #[Test]
    public function a_trial_that_will_not_convert_still_hears_about_its_articles(): void
    {
        // Only a converting trial's sample is quiet. The bare trial is still
        // choosing, and running out of articles is the reason to choose.
        ProjectSubscription::factory()->forProject($this->project)->trialing()->create();

        $this->record(Metric::Articles, 3);
        $this->record(Metric::ContentPlans, 1);

        $this->assertSame(['articles'], $this->entitlement()->toArray()['shortfalls']);
    }

    #[Test]
    public function used_up_ai_answers_are_a_shortfall(): void
    {
        // Manual rechecks spend the same allowance as the scheduled checks,
        // and when they have, the next scheduled check is refused.
        ProjectSubscription::factory()->forProject($this->project)->plan('growth')->create();

        $this->record(Metric::AiAnswers, 200);

        $this->assertSame(['ai_answers'], $this->entitlement()->toArray()['shortfalls']);
    }

    #[Test]
    public function routine_allowances_are_used_up_without_being_a_shortfall(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('growth')->create();

        // The engine makes one of each a period by itself. Reaching the limit
        // is the work having been done.
        $this->record(Metric::ContentPlans, 1);
        $this->record(Metric::SiteAudits, 1);

        $billing = $this->entitlement()->toArray();

        $this->assertFalse($billing['converts_after_trial']);
        $this->assertEqualsCanonicalizing(['content_plans', 'site_audits'], $billing['exhausted']);
        $this->assertSame([], $billing['shortfalls']);

        // Articles are the thing somebody runs short of, and still say so.
        $this->record(Metric::Articles, 30);

        $this->assertSame(['articles'], $this->entitlement()->toArray()['shortfalls']);
    }

    #[Test]
    public function no_subscription_has_nothing_to_convert_and_nothing_to_warn_about(): void
    {
        $billing = Entitlement::none()->toArray();

        $this->assertFalse($billing['converts_after_trial']);
        $this->assertSame([], $billing['shortfalls']);
    }

    #[Test]
    public function the_shared_billing_prop_carries_both_answers_to_every_page(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialing()->plan('growth')->create(['stripe_id' => 'sub_trial']);
        $this->record(Metric::ContentPlans, 1);

        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('billing.status', 'trialing')
                ->where('billing.plan.key', 'growth')
                ->where('billing.converts_after_trial', true)
                ->where('billing.exhausted', ['content_plans'])
                ->where('billing.shortfalls', [])
            );
    }

    #[Test]
    public function the_billing_pages_allowance_card_reads_the_same_shortfalls_as_the_banner(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('growth')->create();
        $this->record(Metric::ContentPlans, 1);
        $this->record(Metric::SiteAudits, 1);

        $this->actingAs($this->owner)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('billing/index')
                ->where('entitlement.shortfalls', [])
                ->where('entitlement.converts_after_trial', false)
            );
    }

    private function record(Metric $metric, int $by): void
    {
        app(Entitlements::class)->record($this->project, $metric, $by);
    }

    private function entitlement(): Entitlement
    {
        $entitlements = app(Entitlements::class);
        $entitlements->forget($this->project);

        return $entitlements->for($this->project);
    }
}
