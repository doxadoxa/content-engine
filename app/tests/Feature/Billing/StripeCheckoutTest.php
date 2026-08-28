<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Contracts\ProviderSubscription;
use App\Billing\Entitlements;
use App\Billing\FakeBillingProvider;
use App\Billing\Metric;
use App\Billing\Plan;
use App\Enums\BillingStatus;
use App\Enums\OnboardingStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Out to Stripe, back again, and the net underneath.
 *
 * Nothing here touches the network: {@see FakeBillingProvider} is bound over
 * the one door in the test environment. That matters more in this subsystem
 * than anywhere else — a billing suite that needs credentials is a billing
 * suite that gets skipped, and this is where an untested branch costs money in
 * both directions.
 */
final class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private FakeBillingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->unbilled()->create([
            'onboarding_status' => OnboardingStatus::Active,
        ]);

        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);

        $provider = app(BillingProvider::class);
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);
        $this->provider = $provider;
    }

    #[Test]
    public function choosing_a_plan_sends_the_owner_to_a_checkout_for_it(): void
    {
        // Asserted as Inertia sees it. Both buttons are Inertia forms, so the
        // request is an XHR — and a plain 302 to another origin is followed by
        // the fetch rather than by the browser, blocked by CORS, and dead-ends
        // on an Inertia error. 409 with `X-Inertia-Location` is the one answer
        // the client understands as "leave this application".
        $this->actingAs($this->owner)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('billing.checkout'), ['plan' => 'medium'])
            ->assertStatus(409)
            ->assertHeader(
                'X-Inertia-Location',
                'https://checkout.stripe.test/medium/'.$this->project->getKey(),
            );

        // The recorded call, not only the redirect: a stub returning a fixed
        // URL would pass while sending everybody to the wrong plan.
        $this->assertSame('medium', $this->provider->checkouts[0]['plan']);
        $this->assertSame($this->project->getKey(), $this->provider->checkouts[0]['project']);
    }

    #[Test]
    public function an_existing_subscriber_changes_plan_rather_than_buying_a_second_one(): void
    {
        // A checkout opens a *new* recurring subscription. Sending somebody who
        // already pays through one leaves two of them at Stripe — billed for
        // both — while the single local row follows whichever webhook arrives
        // last.
        $this->provider->canChangePlan = true;

        ProjectSubscription::factory()->forProject($this->project)->create([
            'stripe_id' => 'sub_test',
        ]);

        $this->actingAs($this->owner)
            ->from(route('billing.index'))
            ->post(route('billing.checkout'), ['plan' => 'small'])
            ->assertRedirect(route('billing.index'));

        $this->assertSame('small', $this->provider->planChanges[0]['plan']);
        $this->assertSame([], $this->provider->checkouts);
    }

    #[Test]
    public function an_admin_resync_moves_the_month_as_well_as_the_status(): void
    {
        // The control exists for a missed renewal webhook, and writing only
        // `period_ends_at` left the local month where it was: counters still
        // exhausted from a month already paid past, while the button reported
        // success.
        $lastMonth = Carbon::now()->subMonth()->startOfDay();
        $admin = User::factory()->create(['is_admin' => true]);

        $subscription = ProjectSubscription::factory()->forProject($this->project)->create([
            'stripe_id' => 'sub_test',
            'period_started_at' => $lastMonth,
            'period_ends_at' => Carbon::now()->startOfDay(),
        ]);

        app(Entitlements::class)
            ->record($this->project, Metric::Articles, 30);

        $thisMonth = Carbon::now()->startOfDay();

        $this->provider->willReport(new ProviderSubscription(
            id: 'sub_test',
            status: BillingStatus::Active,
            rawStatus: 'active',
            priceId: 'price_medium',
            periodStart: $thisMonth,
            periodEnd: $thisMonth->copy()->addMonth(),
            trialEnd: null,
            canceledAt: null,
        ));

        $this->actingAs($admin)
            ->post("/admin/subscriptions/{$subscription->getKey()}/resync")
            ->assertRedirect();

        $this->assertTrue(ProjectSubscription::query()->sole()->period_started_at?->equalTo($thisMonth));
        $this->assertSame(30, app(Entitlements::class)
            ->for($this->project)
            ->remaining(Metric::Articles));
    }

    #[Test]
    public function a_project_with_nothing_at_the_provider_still_goes_to_a_checkout(): void
    {
        // `changePlan` answers false when there is no subscription to change,
        // and the caller falls back rather than refusing.
        $this->actingAs($this->owner)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('billing.checkout'), ['plan' => 'medium'])
            ->assertStatus(409);

        $this->assertSame([], $this->provider->planChanges);
        $this->assertCount(1, $this->provider->checkouts);
    }

    #[Test]
    public function a_first_checkout_carries_the_free_days(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('billing.checkout'), ['plan' => 'medium'])
            ->assertStatus(409);

        $this->assertTrue($this->provider->checkouts[0]['with_trial']);
    }

    #[Test]
    public function a_customer_who_cancelled_does_not_get_the_free_days_again(): void
    {
        // They arrive back here because `changePlan()` declines an invalid
        // subscription. Carrying free days unconditionally handed them the
        // whole window again — repeatably, on the same site, which is the spend
        // bound the domain check exists to hold walked around by another route.
        ProjectSubscription::factory()->forProject($this->project)->canceled()->create([
            'trial_ends_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->owner)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('billing.checkout'), ['plan' => 'medium'])
            ->assertStatus(409);

        $this->assertFalse($this->provider->checkouts[0]['with_trial']);
    }

    #[Test]
    public function a_price_swapped_at_stripe_and_missed_here_is_reconciled(): void
    {
        // Stripe reports the same status and the same period after a plan
        // change, so comparing only those declared the projection healthy while
        // the customer was charged for one tier and served another.
        // No price ids are configured in the test environment, so the ones
        // this comparison turns on have to be said out loud.
        config()->set('billing.plans.1.small.stripe_price', 'price_small');
        config()->set('billing.plans.1.medium.stripe_price', 'price_medium');

        ProjectSubscription::factory()->forProject($this->project)->plan('medium')->create([
            'stripe_id' => 'sub_test',
            'stripe_price' => 'price_medium',
        ]);

        $subscription = ProjectSubscription::query()->sole();

        $this->provider->willReport(new ProviderSubscription(
            id: 'sub_test',
            status: BillingStatus::Active,
            rawStatus: 'active',
            priceId: 'price_small',
            periodStart: $subscription->period_started_at,
            periodEnd: $subscription->period_ends_at,
            trialEnd: null,
            canceledAt: null,
        ));

        $this->reconcile()->assertSuccessful();

        $this->assertSame('small', ProjectSubscription::query()->sole()->plan);
    }

    #[Test]
    public function a_plan_that_is_arranged_rather_than_bought_has_no_checkout(): void
    {
        // Enterprise is a conversation and a custom price. A checkout for it
        // would take somebody's money against limits nobody has agreed.
        $this->actingAs($this->owner)
            ->post(route('billing.checkout'), ['plan' => 'enterprise'])
            ->assertSessionHasErrors('plan');

        $this->assertSame([], $this->provider->checkouts);
    }

    #[Test]
    public function a_plan_nobody_has_heard_of_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('billing.checkout'), ['plan' => 'platinum'])
            ->assertSessionHasErrors('plan');
    }

    #[Test]
    public function an_operator_may_read_the_plans_and_not_buy_one(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'operator']);

        $this->actingAs($operator)->get(route('billing.index'))->assertOk();
        $this->actingAs($operator)
            ->post(route('billing.checkout'), ['plan' => 'medium'])
            ->assertForbidden();
    }

    #[Test]
    public function the_buttons_are_not_drawn_for_somebody_who_may_not_press_them(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'operator']);

        // A control that is not allowed to work should not be drawn rather than
        // drawn and refused.
        $this->actingAs($operator)
            ->get(route('billing.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_pay', false));

        $this->actingAs($this->owner)
            ->get(route('billing.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_pay', true));
    }

    #[Test]
    public function there_is_nothing_to_manage_until_there_is_a_provider_behind_it(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialing()->create();

        // A trial, a comped plan and a hand-assigned Enterprise deal have no
        // portal behind them; sending somebody to one lands them on a Stripe
        // error.
        $this->actingAs($this->owner)
            ->get(route('billing.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('has_provider', false));

        ProjectSubscription::query()->sole()->update(['stripe_id' => 'sub_test']);

        $this->actingAs($this->owner)
            ->get(route('billing.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('has_provider', true));
    }

    #[Test]
    public function the_portal_is_where_a_card_is_changed(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('billing.portal'))
            ->assertStatus(409)
            ->assertHeader(
                'X-Inertia-Location',
                'https://portal.stripe.test/'.$this->owner->getKey(),
            );
    }

    #[Test]
    public function a_provider_that_falls_over_says_so_rather_than_showing_a_stack_trace(): void
    {
        $this->app->instance(BillingProvider::class, new class extends FakeBillingProvider
        {
            public function checkoutUrl(
                User $payer,
                Project $project,
                Plan $plan,
                string $returnUrl,
                bool $withTrial = false,
            ): string {
                throw new RuntimeException('stripe is unwell');
            }
        });

        // The person on the other end of this is trying to give us money, and
        // the failure is ours.
        $this->actingAs($this->owner)
            ->from(route('billing.index'))
            ->post(route('billing.checkout'), ['plan' => 'medium'])
            ->assertRedirect(route('billing.index'));
    }

    // ------------------------------------------------------------ reconciling

    #[Test]
    public function a_projection_that_drifted_from_stripe_is_corrected(): void
    {
        // A missed webhook silently entitles or disentitles a project, and
        // neither raises anything: both look exactly like normal operation.
        ProjectSubscription::factory()->forProject($this->project)->create([
            'stripe_id' => 'sub_test',
            'status' => BillingStatus::Active,
        ]);

        $this->provider->willReport(new ProviderSubscription(
            id: 'sub_test',
            status: BillingStatus::Canceled,
            rawStatus: 'canceled',
            priceId: 'price_medium',
            periodStart: Carbon::now()->subMonth(),
            periodEnd: Carbon::now(),
            trialEnd: null,
            canceledAt: Carbon::now(),
        ));

        $this->reconcile()->assertSuccessful();

        $this->assertSame(BillingStatus::Canceled, ProjectSubscription::query()->sole()->status);
    }

    #[Test]
    public function a_projection_that_agrees_is_left_alone(): void
    {
        $started = Carbon::now()->subDays(3)->startOfDay();

        ProjectSubscription::factory()->forProject($this->project)->create([
            'stripe_id' => 'sub_test',
            'status' => BillingStatus::Active,
            'period_started_at' => $started,
            'period_ends_at' => $started->copy()->addMonth(),
        ]);

        $this->provider->willReport(new ProviderSubscription(
            id: 'sub_test',
            status: BillingStatus::Active,
            rawStatus: 'active',
            priceId: 'price_medium',
            periodStart: $started,
            periodEnd: $started->copy()->addMonth(),
            trialEnd: null,
            canceledAt: null,
        ));

        $this->reconcile()
            ->assertSuccessful()
            ->expectsOutputToContain('Everything agrees with Stripe');
    }

    #[Test]
    public function a_period_that_moved_at_stripe_and_not_here_is_rolled_forward(): void
    {
        // Half the point of the command. When a renewal webhook is lost,
        // nothing else moves a provider-backed period — `billing:sweep` skips
        // these rows deliberately — so the customer's counters stay exhausted
        // from last month and spend keeps accumulating against a one-month fuse
        // until it trips.
        $lastMonth = Carbon::now()->subMonth()->startOfDay();

        ProjectSubscription::factory()->forProject($this->project)->create([
            'stripe_id' => 'sub_test',
            'status' => BillingStatus::Active,
            'period_started_at' => $lastMonth,
            'period_ends_at' => Carbon::now()->startOfDay(),
        ]);

        app(Entitlements::class)
            ->record($this->project, Metric::Articles, 30);

        $thisMonth = Carbon::now()->startOfDay();

        $this->provider->willReport(new ProviderSubscription(
            id: 'sub_test',
            status: BillingStatus::Active,
            rawStatus: 'active',
            priceId: 'price_medium',
            periodStart: $thisMonth,
            periodEnd: $thisMonth->copy()->addMonth(),
            trialEnd: null,
            canceledAt: null,
        ));

        $this->reconcile()->assertSuccessful();

        $subscription = ProjectSubscription::query()->sole();

        $this->assertTrue($subscription->period_started_at?->equalTo($thisMonth));
        // And the counters reset the way they would have if the webhook had
        // arrived, rather than the period moving under an exhausted month.
        $this->assertSame(30, app(Entitlements::class)
            ->for($this->project->refresh())
            ->remaining(Metric::Articles));
    }

    #[Test]
    public function a_row_corrected_to_past_due_gets_a_deadline_with_it(): void
    {
        // Without one there is nothing to expire: `mayPublish()` stays true and
        // the sweep's expiry query, which requires a grace date, never sees the
        // row. It would publish for ever on a dead card.
        ProjectSubscription::factory()->forProject($this->project)->create([
            'stripe_id' => 'sub_test',
            'status' => BillingStatus::Active,
        ]);

        $this->provider->willReport(new ProviderSubscription(
            id: 'sub_test',
            status: BillingStatus::PastDue,
            rawStatus: 'unpaid',
            priceId: 'price_medium',
            periodStart: null,
            periodEnd: null,
            trialEnd: null,
            canceledAt: null,
        ));

        $this->reconcile()->assertSuccessful();

        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame(BillingStatus::PastDue, $subscription->status);
        $this->assertNotNull($subscription->grace_ends_at);
        // Stripe's own word, not our reduced one.
        $this->assertSame('unpaid', $subscription->stripe_status);
    }

    #[Test]
    public function a_provider_that_answers_nothing_does_not_cancel_anybody(): void
    {
        // "Stripe did not answer" and "Stripe has never heard of this" are the
        // same shape from here, and cancelling a paying customer over a
        // five-second API outage is far worse than a stale row for an hour.
        ProjectSubscription::factory()->forProject($this->project)->create([
            'stripe_id' => 'sub_gone',
            'status' => BillingStatus::Active,
        ]);

        $this->reconcile()->assertSuccessful();

        $this->assertSame(BillingStatus::Active, ProjectSubscription::query()->sole()->status);
    }

    #[Test]
    public function a_subscription_with_no_provider_behind_it_is_not_reconciled(): void
    {
        // A trial, a comp and an Enterprise deal assigned from the terminal
        // have nothing at the provider to disagree with.
        ProjectSubscription::factory()->forProject($this->project)->trialing()->create();

        $this->reconcile()
            ->assertSuccessful()
            ->expectsOutputToContain('No subscriptions have a provider behind them yet');
    }

    private function reconcile(): PendingCommand
    {
        /** @var PendingCommand $pending */
        $pending = $this->artisan('billing:reconcile');

        return $pending;
    }
}
