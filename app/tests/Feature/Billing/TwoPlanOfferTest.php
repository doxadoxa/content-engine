<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Entitlements;
use App\Billing\FakeBillingProvider;
use App\Billing\Metric;
use App\Billing\PlanCatalog;
use App\Billing\PlanSelection;
use App\Billing\StripeWebhook;
use App\Billing\Subscriptions;
use App\Enums\OnboardingStatus;
use App\Models\ArticleSchedule;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TwoPlanOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.version' => 4, 'billing.default_plan' => 'starter']);
        Queue::fake();
    }

    public function test_public_offer_has_two_usd_plans_and_keeps_historical_catalogs(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('pricing.plans', 2)
            ->where('pricing.plans.0.key', 'starter')->where('pricing.plans.0.price_cents', 2900)
            ->where('pricing.plans.0.version', 4)->where('pricing.plans.0.currency', 'usd')
            ->where('pricing.plans.0.limits.articles', 12)->where('pricing.plans.0.limits.ai_answers', 12)
            ->where('pricing.plans.1.key', 'growth')->where('pricing.plans.1.price_cents', 8900)
            ->where('pricing.plans.1.limits.articles', 30)->where('pricing.plans.1.limits.ai_answers', 200));
        $catalog = app(PlanCatalog::class);
        $this->assertSame(0, $catalog->get('local-search', 2)->limit('articles'));
        $this->assertSame(30, $catalog->get('local-search', 3)->limit('articles'));
        $this->assertSame('eur', $catalog->get('small', 1)->currency);
        $this->assertSame(0, $catalog->get('starter')->limit('page_improvements'));
        $this->assertSame(4, $catalog->get('growth')->limit('page_improvements'));
    }

    /** @return array<string, array{string}> */
    public static function offers(): array
    {
        return ['starter' => ['starter'], 'growth' => ['growth']];
    }

    #[DataProvider('offers')]
    public function test_selection_survives_registration_and_is_visible_on_the_wizard(string $plan): void
    {
        $this->get('/start?plan='.$plan.'&plan_version=4')->assertRedirect(route('register'))
            ->assertSessionHas(PlanSelection::SESSION_KEY, ['key' => $plan, 'version' => 4]);
        $this->get('/register')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('selectedPlan.key', $plan));
        $this->post('/register', ['name' => 'Pricing test', 'email' => $plan.'@example.test', 'password' => 'Example-pricing-password!9', 'password_confirmation' => 'Example-pricing-password!9'])->assertRedirect();
        $user = User::query()->where('email', $plan.'@example.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user)->get('/onboarding')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('selectedPlan.key', $plan));
    }

    #[DataProvider('offers')]
    public function test_selected_trial_plan_reaches_checkout_and_paid_conversion(string $plan): void
    {
        [$project, $owner] = $this->project();
        $project->forceFill(['onboarding_status' => OnboardingStatus::Draft, 'onboarding' => [
            'offer' => ['key' => $plan, 'version' => 4],
            'business' => ['description' => 'We clean offices in Lisbon.', 'audiences' => ['Office managers']],
        ]])->save();
        $this->actingAs($owner)->withHeaders(['X-Inertia' => 'true'])->post('/onboarding/'.$project->id.'/launch')->assertStatus(409);
        $provider = $this->provider();
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);
        $this->assertSame($plan, $provider->checkouts[0]['plan']);
        $this->assertTrue($provider->checkouts[0]['with_trial']);
        // Stop this billing projection test from launching unrelated pipelines.
        $project->forceFill(['onboarding_status' => OnboardingStatus::Active])->save();
        $trialStart = now()->startOfSecond();
        $this->webhook($project, $plan, 'trialing', $trialStart->getTimestamp(), $trialStart->copy()->addDays(3)->getTimestamp(), 'evt_trial');
        $trial = app(Entitlements::class)->for($project);
        $this->assertSame(3, $trial->allowance->limit('articles'));
        app(Entitlements::class)->record($project, Metric::Articles, 3);
        $paidStart = $trialStart->copy()->addDays(3);
        $this->webhook($project, $plan, 'active', $paidStart->getTimestamp(), $paidStart->copy()->addMonthNoOverflow()->getTimestamp(), 'evt_paid');
        $paid = app(Entitlements::class)->for($project);
        $this->assertSame($plan === 'starter' ? 12 : 30, $paid->plan->limit('articles'));
        $this->assertSame(0, $paid->used(Metric::Articles));
    }

    public function test_a_saved_project_offer_wins_over_another_tabs_session_choice(): void
    {
        [$project, $owner] = $this->project();
        $project->forceFill(['onboarding_status' => OnboardingStatus::Draft, 'onboarding' => ['offer' => ['key' => 'starter', 'version' => 4]]])->save();
        $this->actingAs($owner)->withSession([PlanSelection::SESSION_KEY => ['key' => 'growth', 'version' => 4]])
            ->get('/onboarding')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('selectedPlan.key', 'starter'));
        $this->postJson('/onboarding/'.$project->id.'/save', ['step' => 'offer', 'answers' => ['key' => 'growth', 'version' => 4]])->assertOk();
        $this->assertSame('growth', $project->refresh()->onboarding['offer']['key']);
        $this->assertSame(7, $project->weekly_target);
    }

    public function test_stale_offer_cannot_be_silently_repriced(): void
    {
        $this->get('/start?plan=local-search&plan_version=3')->assertSessionHasErrors('plan');
        [$project, $owner] = $this->project();
        $this->actingAs($owner)->post('/billing/checkout', ['plan' => 'growth', 'plan_version' => 3])->assertSessionHasErrors('plan');
        $this->assertSame([], $this->provider()->checkouts);
    }

    public function test_same_period_upgrade_retains_used_articles_and_duplicate_events_do_not_reset_them(): void
    {
        [$project] = $this->project('starter');
        $subscription = ProjectSubscription::query()->where('project_id', $project->id)->firstOrFail();
        app(Entitlements::class)->record($project, Metric::Articles, 10);
        app(Entitlements::class)->record($project, Metric::AiAnswers, 12);
        $this->webhook($project, 'growth', 'active', $subscription->periodStart()->getTimestamp(), $subscription->period_ends_at->getTimestamp(), 'evt_upgrade');
        $this->webhook($project, 'growth', 'active', $subscription->periodStart()->getTimestamp(), $subscription->period_ends_at->getTimestamp(), 'evt_upgrade');
        $entitlement = app(Entitlements::class)->for($project);
        $this->assertSame(10, $entitlement->used(Metric::Articles));
        $this->assertSame(12, $entitlement->used(Metric::AiAnswers));
        $this->assertSame(30, $entitlement->plan->limit('articles'));
    }

    public function test_downgrade_requires_acknowledgement_is_idempotent_and_waits_for_renewal(): void
    {
        [$project, $owner] = $this->project('growth');
        $provider = $this->provider();
        $provider->canChangePlan = true;
        app(Entitlements::class)->record($project, Metric::Articles, 20);
        $this->actingAs($owner)->post('/billing/checkout', ['plan' => 'starter', 'plan_version' => 4])->assertSessionHasErrors('plan');
        $this->assertSame([], $provider->scheduledChanges);
        foreach (range(1, 2) as $attempt) {
            $this->post('/billing/checkout', ['plan' => 'starter', 'plan_version' => 4, 'acknowledge_downgrade' => '1'])->assertRedirect();
        }
        $this->assertCount(1, $provider->scheduledChanges);
        $this->assertSame([], $provider->checkouts);
        $subscription = ProjectSubscription::query()->where('project_id', $project->id)->firstOrFail();
        $this->assertSame('growth', $subscription->plan);
        $this->assertSame('starter', $subscription->pending_plan);
        $this->assertSame(20, app(Entitlements::class)->for($project)->used(Metric::Articles));
        $renewal = $subscription->period_ends_at;
        $this->webhook($project, 'starter', 'active', $renewal->getTimestamp(), $renewal->copy()->addMonthNoOverflow()->getTimestamp(), 'evt_renewal');
        $this->assertNull($subscription->refresh()->pending_plan);
        $this->assertSame('starter', $subscription->plan);
        $this->assertSame(0, app(Entitlements::class)->for($project)->used(Metric::Articles));
    }

    public function test_pending_downgrade_can_be_canceled_without_changing_usage(): void
    {
        [$project, $owner] = $this->project('growth');
        ProjectSubscription::query()->where('project_id', $project->id)->update(['pending_plan' => 'starter', 'pending_plan_version' => 4, 'pending_plan_at' => now()->addDays(20), 'stripe_schedule_id' => 'sched_test']);
        $this->provider()->canChangePlan = true;
        app(Entitlements::class)->record($project, Metric::Articles, 18);
        $this->actingAs($owner)->post('/billing/cancel-change')->assertRedirect();
        $this->assertNull(ProjectSubscription::query()->where('project_id', $project->id)->value('pending_plan'));
        $this->assertSame(18, app(Entitlements::class)->for($project)->used(Metric::Articles));
    }

    public function test_one_language_is_enforced_before_saving_onboarding(): void
    {
        [$project, $owner] = $this->project();
        $project->forceFill(['onboarding_status' => OnboardingStatus::Draft, 'onboarding' => ['offer' => ['key' => 'starter', 'version' => 4]]])->save();
        $this->actingAs($owner)->postJson('/onboarding/'.$project->id.'/save', ['step' => 'market', 'answers' => ['language' => 'en', 'extra_languages' => ['pt']]])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.extra_languages');
        $this->assertSame(['en'], $project->refresh()->locales);
    }

    public function test_downgrade_preview_includes_real_future_schedule_states(): void
    {
        [$project, $owner] = $this->project('growth');
        $subscription = ProjectSubscription::query()->where('project_id', $project->id)->firstOrFail();
        foreach (['active', 'blocked', 'dispatching', 'paused'] as $status) {
            $item = ContentItem::factory()->create(['title' => 'Future '.$status]);
            ArticleSchedule::query()->create(['content_item_id' => $item->id, 'publish_at' => $subscription->period_ends_at->copy()->addDay(),
                'local_date' => $subscription->period_ends_at->copy()->addDay()->toDateString(), 'local_time' => '09:00', 'timezone' => 'UTC',
                'mode' => 'automatic', 'status' => $status, 'origin' => 'manual', 'version' => 1]);
        }
        $this->actingAs($owner)->get('/billing')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.0.change.at_renewal', true)->has('plans.0.change.schedules', 4));
    }

    public function test_upgrading_uses_growth_pacing_but_keeps_a_deliberately_lower_preference(): void
    {
        foreach ([3 => 7, 2 => 2] as $before => $after) {
            [$project] = $this->project('starter');
            $project->forceFill(['weekly_target' => $before])->save();
            app(Subscriptions::class)->changeWithinPeriod($project, app(PlanCatalog::class)->get('growth', 4));
            $this->assertSame($after, $project->refresh()->weekly_target);
            ProjectSubscription::query()->where('project_id', $project->id)->update(['stripe_id' => null]);
        }
    }

    public function test_old_draft_can_be_opened_but_must_choose_a_current_offer_before_launch(): void
    {
        [$project, $owner] = $this->project();
        $project->forceFill(['onboarding_status' => OnboardingStatus::Draft, 'onboarding' => ['offer' => ['key' => 'local-search', 'version' => 3]]])->save();
        $this->actingAs($owner)->get('/onboarding')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('selectedPlan.version', 3));
        $this->post('/onboarding/'.$project->id.'/launch')->assertSessionHasErrors('plan');
        $this->assertSame(OnboardingStatus::Draft, $project->refresh()->onboarding_status);
        $this->assertSame([], $this->provider()->checkouts);
    }

    public function test_provider_price_wins_when_a_portal_change_retains_old_metadata(): void
    {
        config(['billing.plans.4.starter.stripe_price' => 'price_starter', 'billing.plans.4.growth.stripe_price' => 'price_growth']);
        [$project] = $this->project('growth');
        $subscription = ProjectSubscription::query()->where('project_id', $project->id)->firstOrFail();
        app(Entitlements::class)->record($project, Metric::Articles, 10);
        app(StripeWebhook::class)->handle(['id' => 'evt_portal', 'type' => 'customer.subscription.updated', 'data' => ['object' => [
            'id' => 'sub_test', 'customer' => 'cus_test', 'status' => 'active',
            'current_period_start' => $subscription->periodStart()->getTimestamp(), 'current_period_end' => $subscription->period_ends_at->getTimestamp(),
            'metadata' => ['project_id' => $project->id, 'plan' => 'growth', 'plan_version' => '4'],
            'items' => ['data' => [['price' => ['id' => 'price_starter']]]],
        ]]]);
        $this->assertSame('starter', $subscription->refresh()->plan);
        $this->assertSame(10, app(Entitlements::class)->for($project)->used(Metric::Articles));
    }

    public function test_a_provider_released_schedule_clears_the_pending_change_without_resetting_usage(): void
    {
        [$project] = $this->project('growth');
        $subscription = ProjectSubscription::query()->where('project_id', $project->id)->firstOrFail();
        $subscription->fill(['pending_plan' => 'starter', 'pending_plan_version' => 4, 'pending_plan_at' => $subscription->period_ends_at, 'stripe_schedule_id' => 'sched_test'])->save();
        app(Entitlements::class)->record($project, Metric::Articles, 10);
        app(StripeWebhook::class)->handle(['id' => 'evt_release', 'type' => 'customer.subscription.updated', 'data' => ['object' => [
            'id' => 'sub_test', 'customer' => 'cus_test', 'status' => 'active', 'schedule' => null,
            'current_period_start' => $subscription->periodStart()->getTimestamp(), 'current_period_end' => $subscription->period_ends_at->getTimestamp(),
            'metadata' => ['project_id' => $project->id, 'plan' => 'growth', 'plan_version' => '4'],
        ]]]);
        $this->assertNull($subscription->refresh()->pending_plan);
        $this->assertSame('growth', $subscription->plan);
        $this->assertSame(10, app(Entitlements::class)->for($project)->used(Metric::Articles));
    }

    private function provider(): FakeBillingProvider
    {
        $provider = app(BillingProvider::class);
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);

        return $provider;
    }

    /** @return array{Project, User} */
    private function project(?string $plan = null): array
    {
        $project = Project::factory()->unbilled()->create();
        $owner = User::factory()->create(['stripe_id' => 'cus_test']);
        $owner->projects()->attach($project, ['role' => 'owner']);
        app(CurrentProject::class)->set($project);
        if ($plan !== null) {
            ProjectSubscription::factory()->forProject($project)->create(['plan' => $plan, 'plan_version' => 4, 'billing_user_id' => $owner->id, 'stripe_id' => 'sub_test']);
        }

        return [$project, $owner];
    }

    private function webhook(Project $project, string $plan, string $status, int $start, int $end, string $event): void
    {
        app(StripeWebhook::class)->handle(['id' => $event, 'type' => 'customer.subscription.updated', 'data' => ['object' => [
            'id' => 'sub_test', 'customer' => 'cus_test', 'status' => $status,
            'current_period_start' => $start, 'current_period_end' => $end,
            'trial_end' => $status === 'trialing' ? $end : null,
            'metadata' => ['project_id' => $project->id, 'plan' => $plan, 'plan_version' => '4'],
        ]]]);
    }
}
