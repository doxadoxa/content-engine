<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Contracts\ProviderSubscription;
use App\Billing\FakeBillingProvider;
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
        $this->actingAs($this->owner)
            ->post(route('billing.checkout'), ['plan' => 'medium'])
            ->assertRedirect('https://checkout.stripe.test/medium/'.$this->project->getKey());

        // The recorded call, not only the redirect: a stub returning a fixed
        // URL would pass while sending everybody to the wrong plan.
        $this->assertSame('medium', $this->provider->checkouts[0]['plan']);
        $this->assertSame($this->project->getKey(), $this->provider->checkouts[0]['project']);
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
            ->post(route('billing.portal'))
            ->assertRedirect('https://portal.stripe.test/'.$this->owner->getKey());
    }

    #[Test]
    public function a_provider_that_falls_over_says_so_rather_than_showing_a_stack_trace(): void
    {
        $this->app->instance(BillingProvider::class, new class extends FakeBillingProvider
        {
            public function checkoutUrl(User $payer, Project $project, Plan $plan, string $returnUrl): string
            {
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
        ProjectSubscription::factory()->forProject($this->project)->create([
            'stripe_id' => 'sub_test',
            'status' => BillingStatus::Active,
        ]);

        $this->provider->willReport(new ProviderSubscription(
            id: 'sub_test',
            status: BillingStatus::Active,
            priceId: 'price_medium',
            periodStart: Carbon::now(),
            periodEnd: Carbon::now()->addMonth(),
            trialEnd: null,
            canceledAt: null,
        ));

        $this->reconcile()
            ->assertSuccessful()
            ->expectsOutputToContain('Everything agrees with Stripe');
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
