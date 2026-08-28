<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Entitlement;
use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Billing\StripeWebhook;
use App\Enums\BillingStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\StripeEvent;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What Stripe tells us, and the several ways it tells us twice.
 *
 * Stripe delivers at least once and says so. Every assertion about replay here
 * is about money: a `customer.subscription.updated` re-delivered after a deploy
 * must not hand a customer a second month's quota for one month's payment.
 */
final class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->unbilled()->create();
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function a_checkout_that_succeeded_puts_the_project_on_its_plan(): void
    {
        $this->send($this->subscriptionPayload('active'));

        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame('medium', $subscription->plan);
        $this->assertSame(BillingStatus::Active, $subscription->status);
        $this->assertSame('sub_test', $subscription->stripe_id);
        $this->assertTrue(app(Entitlements::class)->for($this->project)->mayGenerate());
    }

    #[Test]
    public function the_same_event_twice_changes_nothing_the_second_time(): void
    {
        $payload = $this->subscriptionPayload('active');

        $this->send($payload);
        app(Entitlements::class)->record($this->project, Metric::Articles, 4);

        // The identical event, re-delivered. If this renewed, the customer
        // would get a fresh thirty articles for one month's money.
        $this->send($payload);

        $this->assertSame(1, StripeEvent::query()->count());
        $this->assertSame(4, $this->entitlement()->used(Metric::Articles));
    }

    #[Test]
    public function a_repeated_update_that_is_not_a_renewal_does_not_reset_the_month(): void
    {
        // A distinct event id, same period — Stripe touching metadata, a card
        // swapped, a quantity changed. Only a *new* period may reset counters.
        $this->send($this->subscriptionPayload('active', eventId: 'evt_one'));
        app(Entitlements::class)->record($this->project, Metric::Articles, 7);

        $this->send($this->subscriptionPayload('active', eventId: 'evt_two'));

        $this->assertSame(7, $this->entitlement()->used(Metric::Articles));
    }

    #[Test]
    public function a_new_period_does_reset_the_month(): void
    {
        $this->send($this->subscriptionPayload('active', eventId: 'evt_one'));
        app(Entitlements::class)->record($this->project, Metric::Articles, 7);

        $this->send($this->subscriptionPayload(
            'active',
            eventId: 'evt_renewal',
            periodStart: Carbon::now()->addMonth(),
        ));

        // A renewal is a month bought, not the remainder of one.
        $this->assertSame(0, $this->entitlement()->used(Metric::Articles));
    }

    #[Test]
    public function a_failed_payment_stops_generating_and_keeps_publishing(): void
    {
        $this->send($this->subscriptionPayload('active'));
        $this->send($this->invoicePayload('invoice.payment_failed'));

        $entitlement = $this->entitlement();

        $this->assertSame(BillingStatus::PastDue, $entitlement->status);
        $this->assertFalse($entitlement->mayGenerate());
        $this->assertTrue($entitlement->mayPublish());
        $this->assertNotNull(ProjectSubscription::query()->sole()->grace_ends_at);
    }

    #[Test]
    public function a_payment_that_finally_succeeds_clears_the_dunning(): void
    {
        $this->send($this->subscriptionPayload('active'));
        $this->send($this->invoicePayload('invoice.payment_failed'));
        $this->send($this->invoicePayload('invoice.payment_succeeded', eventId: 'evt_paid'));

        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame(BillingStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
    }

    #[Test]
    public function a_paid_invoice_does_not_move_the_period(): void
    {
        $this->send($this->subscriptionPayload('active'));
        app(Entitlements::class)->record($this->project, Metric::Articles, 3);

        // The period and the counters move on `customer.subscription.updated`,
        // which carries the new window. An invoice does not, and renewing from
        // both events would reset a customer's month twice per renewal.
        $this->send($this->invoicePayload('invoice.payment_succeeded', eventId: 'evt_paid'));

        $this->assertSame(3, $this->entitlement()->used(Metric::Articles));
    }

    #[Test]
    public function a_deleted_subscription_ends_the_entitlement(): void
    {
        $this->send($this->subscriptionPayload('active'));
        $this->send($this->subscriptionPayload('canceled', type: 'customer.subscription.deleted', eventId: 'evt_gone'));

        $entitlement = $this->entitlement();

        $this->assertSame(BillingStatus::Canceled, $entitlement->status);
        $this->assertFalse($entitlement->mayGenerate());
        $this->assertFalse($entitlement->mayPublish());
    }

    #[Test]
    public function an_unrecognised_stripe_status_is_treated_as_past_due(): void
    {
        // The two ways to be wrong are not symmetrical. Treating an unknown
        // state as entitled gives away service silently and for ever; treating
        // it as past due stops generation, keeps publishing, and is visible to
        // the customer within a day.
        $this->send($this->subscriptionPayload('some_status_stripe_added_later'));

        $this->assertSame(BillingStatus::PastDue, ProjectSubscription::query()->sole()->status);
    }

    #[Test]
    public function an_event_about_a_project_we_do_not_have_is_recorded_and_ignored(): void
    {
        $payload = $this->subscriptionPayload('active');
        $payload['data']['object']['metadata']['project_id'] = '01xxxxxxxxxxxxxxxxxxxxxxxx';

        $this->send($payload);

        // Recorded rather than dropped: "arrived, concerned nobody" is a fact
        // worth being able to see when somebody asks why nothing happened.
        $this->assertSame('unmatched', StripeEvent::query()->sole()->outcome);
        $this->assertSame(0, ProjectSubscription::query()->count());
    }

    #[Test]
    public function the_metadata_decides_the_plan_and_not_the_price(): void
    {
        // Metadata wins because it is what a checkout stamps and it survives a
        // subscription being edited in the Stripe dashboard. Which is exactly
        // why a *swap* has to rewrite it: change only the price and the update
        // that follows still names the old plan, so the local entitlement sits
        // on the old tier while Stripe charges the new amount.
        $payload = $this->subscriptionPayload('active');
        $payload['data']['object']['metadata']['plan'] = 'small';
        // The price still says Medium. Metadata is the one that must win.
        $payload['data']['object']['items']['data'][0]['price']['id'] = 'price_medium';

        $this->send($payload);

        $this->assertSame('small', ProjectSubscription::query()->sole()->plan);
        $this->assertSame(10, $this->entitlement()->limit('articles'));
    }

    #[Test]
    public function a_subscription_naming_no_plan_we_know_is_refused_rather_than_guessed(): void
    {
        $payload = $this->subscriptionPayload('active');
        $payload['data']['object']['metadata']['plan'] = 'platinum';
        $payload['data']['object']['items']['data'][0]['price']['id'] = 'price_nobody_configured';

        $this->send($payload);

        $this->assertSame('unknown_plan', StripeEvent::query()->sole()->outcome);
        $this->assertSame(0, ProjectSubscription::query()->count());
    }

    #[Test]
    public function the_payer_is_the_account_that_holds_the_stripe_customer(): void
    {
        $payer = User::factory()->create(['stripe_id' => 'cus_test']);

        $this->send($this->subscriptionPayload('active'));

        $this->assertSame($payer->getKey(), ProjectSubscription::query()->sole()->billing_user_id);
    }

    #[Test]
    public function a_past_due_arriving_before_the_failed_invoice_still_gets_a_deadline(): void
    {
        $this->send($this->subscriptionPayload('active'));

        // Stripe does not order its deliveries, so the subscription update can
        // land before the invoice event that explains it. Writing the status as
        // a plain column left `grace_ends_at` null, and the later
        // `invoice.payment_failed` then found the status already past due and
        // did nothing — no deadline, nothing for the sweep to expire, and the
        // project published indefinitely on a dead card.
        $this->send($this->subscriptionPayload('past_due', eventId: 'evt_pastdue'));

        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame(BillingStatus::PastDue, $subscription->status);
        $this->assertNotNull($subscription->grace_ends_at);
        // Stripe's own word survives beside our reduced one.
        $this->assertSame('past_due', $subscription->stripe_status);
    }

    #[Test]
    public function a_renewal_keeps_the_arrangement_it_is_renewing(): void
    {
        $this->send($this->subscriptionPayload('active'));

        ProjectSubscription::query()->sole()->update([
            'limit_overrides' => ['articles' => 500],
        ]);

        $this->send($this->subscriptionPayload(
            'active',
            eventId: 'evt_renewal',
            periodStart: Carbon::now()->addMonth(),
        ));

        // A renewal is not a plan change. Routing it through `assign()` cleared
        // an Enterprise customer's bespoke limits at their first renewal and
        // moved every paying customer onto the newest price list — which is
        // exactly what `plan_version` exists to prevent.
        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame(['articles' => 500], $subscription->limit_overrides);
        $this->assertSame(500, $this->entitlement()->limit('articles'));
    }

    #[Test]
    public function a_change_of_plan_is_a_new_arrangement_and_clears_the_old_one(): void
    {
        $this->send($this->subscriptionPayload('active'));
        ProjectSubscription::query()->sole()->update(['limit_overrides' => ['articles' => 500]]);

        $payload = $this->subscriptionPayload('active', eventId: 'evt_downgrade');
        $payload['data']['object']['metadata']['plan'] = 'small';
        $payload['data']['object']['items']['data'][0]['price']['id'] = 'price_small';

        $this->send($payload);

        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame('small', $subscription->plan);
        $this->assertSame([], $subscription->limit_overrides);
        $this->assertSame(10, $this->entitlement()->limit('articles'));
    }

    #[Test]
    public function an_update_carrying_no_period_does_not_reset_the_month(): void
    {
        $this->send($this->subscriptionPayload('active'));
        app(Entitlements::class)->record($this->project, Metric::Articles, 6);

        // A metadata-only edit, or a payload shape the `items` fallback misses.
        // Falling back to "now" here never equals the stored period start, so
        // it read as a renewal: the customer's month moved and their counters
        // were wiped — a fresh month's quota for one month's money.
        $payload = $this->subscriptionPayload('active', eventId: 'evt_touch');
        unset(
            $payload['data']['object']['current_period_start'],
            $payload['data']['object']['current_period_end'],
        );

        $this->send($payload);

        $this->assertSame(6, $this->entitlement()->used(Metric::Articles));
        $this->assertSame('synced', StripeEvent::query()->whereKey('evt_touch')->sole()->outcome);
    }

    #[Test]
    public function a_trialing_subscription_is_stored_as_trialing(): void
    {
        // Stored as `active`, it disagreed with Stripe for ever: the panel
        // flagged it and `billing:reconcile` "corrected" the same drift every
        // night without it going away.
        $this->send($this->subscriptionPayload('trialing'));

        $this->assertSame(BillingStatus::Trialing, ProjectSubscription::query()->sole()->status);
    }

    #[Test]
    public function a_subscription_set_to_cancel_at_period_end_keeps_that_date(): void
    {
        $payload = $this->subscriptionPayload('active');
        $payload['data']['object']['canceled_at'] = Carbon::now()->getTimestamp();

        $this->send($payload);

        // Still active, and still carrying the date it will end on — which
        // clearing `canceled_at` on the healthy path threw away.
        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame(BillingStatus::Active, $subscription->status);
        $this->assertNotNull($subscription->canceled_at);
    }

    #[Test]
    public function a_subscription_with_no_period_on_it_is_still_created(): void
    {
        // The event id is claimed the moment it arrives, so an event that fell
        // through this branch silently was an event Stripe would never
        // successfully redeliver: a completed checkout with no entitlement, and
        // nothing anywhere saying so.
        $payload = $this->subscriptionPayload('active');
        unset(
            $payload['data']['object']['current_period_start'],
            $payload['data']['object']['current_period_end'],
        );

        $this->send($payload);

        $this->assertSame('subscribed', StripeEvent::query()->sole()->outcome);
        $this->assertTrue($this->entitlement()->mayGenerate());
    }

    #[Test]
    public function a_plan_version_is_read_back_from_the_checkout_that_sold_it(): void
    {
        // A session opened under one price list and completed after the next
        // was published bought the one it was opened under. It would be quite
        // a trick to charge somebody against a list that did not exist when
        // they clicked.
        $payload = $this->subscriptionPayload('active');
        $payload['data']['object']['metadata']['plan_version'] = '1';

        $this->send($payload);

        $this->assertSame(1, ProjectSubscription::query()->sole()->plan_version);
    }

    #[Test]
    public function an_older_event_arriving_late_does_not_undo_a_newer_one(): void
    {
        $this->send($this->subscriptionPayload('active', at: Carbon::now()));

        // Stripe does not promise order. A cancellation followed by an older
        // update carrying `active` would re-entitle a cancelled project.
        $this->send($this->subscriptionPayload(
            'canceled',
            type: 'customer.subscription.deleted',
            eventId: 'evt_gone',
            at: Carbon::now()->addMinute(),
        ));

        $this->send($this->subscriptionPayload(
            'active',
            eventId: 'evt_late',
            at: Carbon::now()->subMinutes(5),
        ));

        $this->assertSame('stale', StripeEvent::query()->whereKey('evt_late')->sole()->outcome);
        $this->assertSame(BillingStatus::Canceled, ProjectSubscription::query()->sole()->status);
        $this->assertFalse($this->entitlement()->mayGenerate());
    }

    #[Test]
    public function an_older_period_cannot_roll_the_counters_backwards(): void
    {
        $now = Carbon::now()->startOfDay();

        $this->send($this->subscriptionPayload('active', periodStart: $now, at: $now));
        app(Entitlements::class)->record($this->project, Metric::Articles, 9);

        // An update describing last month's window, delivered late.
        $this->send($this->subscriptionPayload(
            'active',
            eventId: 'evt_old_period',
            periodStart: $now->copy()->subMonth(),
            at: $now->copy()->subHour(),
        ));

        $this->assertSame(9, $this->entitlement()->used(Metric::Articles));
    }

    #[Test]
    public function a_healthy_subscription_starts_an_engine_billing_stopped(): void
    {
        // The trial-conversion webhook arriving an hour after the sweep
        // cancelled it. Restoring only the subscription fields would leave a
        // now-paying customer permanently silent: `engine:tick` considers
        // active projects only, and nothing else was going to undo the pause.
        $this->send($this->subscriptionPayload('active'));

        $this->project->forceFill(['status' => ProjectStatus::Paused])->save();
        ProjectSubscription::query()->sole()->forceFill(['paused_by_billing' => true])->save();

        $this->send($this->subscriptionPayload('active', eventId: 'evt_back', at: Carbon::now()->addMinute()));

        $this->assertSame(ProjectStatus::Active, $this->project->fresh()?->status);
        $this->assertFalse(ProjectSubscription::query()->sole()->paused_by_billing);
    }

    #[Test]
    public function a_pause_somebody_chose_is_not_undone_by_a_payment(): void
    {
        $this->send($this->subscriptionPayload('active'));

        // An operator paused this deliberately. A payment succeeding is not an
        // argument against their reason.
        $this->project->forceFill(['status' => ProjectStatus::Paused])->save();

        $this->send($this->subscriptionPayload('active', eventId: 'evt_paid', at: Carbon::now()->addMinute()));

        $this->assertSame(ProjectStatus::Paused, $this->project->fresh()?->status);
    }

    #[Test]
    public function an_older_paid_invoice_does_not_clear_a_newer_failure(): void
    {
        $this->send($this->subscriptionPayload('active', at: Carbon::now()));
        $this->send($this->invoicePayload(
            'invoice.payment_failed',
            eventId: 'evt_failed',
            at: Carbon::now()->addMinutes(2),
        ));

        // Invoice events were excluded from the watermark on the grounds that
        // they "do one narrow thing" and are self-correcting. They are not:
        // `paid()` clears past_due and its deadline unconditionally, so an
        // older success delivered after a newer failure re-enabled generation
        // for a customer whose current invoice is still unpaid.
        $this->send($this->invoicePayload(
            'invoice.payment_succeeded',
            eventId: 'evt_old_paid',
            at: Carbon::now()->subMinutes(5),
        ));

        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame(BillingStatus::PastDue, $subscription->status);
        $this->assertNotNull($subscription->grace_ends_at);
        $this->assertSame('stale', StripeEvent::query()->whereKey('evt_old_paid')->sole()->outcome);
    }

    #[Test]
    public function an_older_failure_cannot_undo_a_newer_payment(): void
    {
        $this->send($this->subscriptionPayload('active', at: Carbon::now()));

        // The mirror of the case above, and the one `paid()` left open: it was
        // the only state-changing path that did not move the watermark, so a
        // newer success processed first left the older failure looking current
        // and it put the subscription straight back into dunning.
        $this->send($this->invoicePayload(
            'invoice.payment_succeeded',
            eventId: 'evt_paid',
            at: Carbon::now()->addMinutes(2),
        ));

        $this->send($this->invoicePayload(
            'invoice.payment_failed',
            eventId: 'evt_old_failure',
            at: Carbon::now()->addMinute(),
        ));

        $this->assertSame(BillingStatus::Active, ProjectSubscription::query()->sole()->status);
        $this->assertSame('stale', StripeEvent::query()->whereKey('evt_old_failure')->sole()->outcome);
    }

    #[Test]
    public function a_payment_that_really_is_the_latest_still_clears_the_dunning(): void
    {
        $this->send($this->subscriptionPayload('active', at: Carbon::now()));
        $this->send($this->invoicePayload(
            'invoice.payment_failed',
            eventId: 'evt_failed',
            at: Carbon::now()->addMinute(),
        ));

        $this->send($this->invoicePayload(
            'invoice.payment_succeeded',
            eventId: 'evt_paid',
            at: Carbon::now()->addMinutes(2),
        ));

        $this->assertSame(BillingStatus::Active, ProjectSubscription::query()->sole()->status);
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload): void
    {
        app(StripeWebhook::class)->handle($payload);
        app(Entitlements::class)->forget();
    }

    private function entitlement(): Entitlement
    {
        app(Entitlements::class)->forget();

        return app(Entitlements::class)->for($this->project);
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(
        string $status,
        string $type = 'customer.subscription.updated',
        string $eventId = 'evt_test',
        ?Carbon $periodStart = null,
        ?Carbon $at = null,
    ): array {
        $start = $periodStart ?? Carbon::now()->startOfDay();

        return [
            'id' => $eventId,
            'type' => $type,
            // Stripe's own timestamp for the event, which is what orders them.
            'created' => ($at ?? Carbon::now())->getTimestamp(),
            'data' => ['object' => [
                'id' => 'sub_test',
                'status' => $status,
                'customer' => 'cus_test',
                'current_period_start' => $start->getTimestamp(),
                'current_period_end' => $start->copy()->addMonth()->getTimestamp(),
                'trial_end' => null,
                'canceled_at' => null,
                'metadata' => [
                    'project_id' => $this->project->getKey(),
                    'plan' => 'medium',
                    'plan_version' => '1',
                ],
                'items' => ['data' => [['price' => ['id' => 'price_medium']]]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function invoicePayload(string $type, string $eventId = 'evt_invoice', ?Carbon $at = null): array
    {
        return [
            'id' => $eventId,
            'type' => $type,
            // Stripe's own timestamp for the event, which is what orders them.
            'created' => ($at ?? Carbon::now())->getTimestamp(),
            'data' => ['object' => [
                'id' => 'in_test',
                'subscription' => 'sub_test',
            ]],
        ];
    }
}
