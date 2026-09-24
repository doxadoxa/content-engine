<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Contracts\ProviderSubscription;
use App\Enums\BillingStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Subscription as StripeSubscription;
use Stripe\SubscriptionSchedule;

/**
 * Stripe, behind the one door.
 *
 * A subscription is named after the project it pays for — Cashier calls that
 * the subscription "type", and it is why one saved card can hold several. The
 * ULID goes in verbatim: `newSubscription($project->id, …)`, and everything
 * that later asks "which project is this Stripe subscription about" reads that
 * name back rather than joining through a table we would have to keep in step.
 *
 * Hosted Checkout and the hosted Portal, never a card form here. No card data
 * touches this application, tax and promotion codes come free, and plan
 * changes, invoice history and cancellation are six screens Stripe already
 * keeps correct as its own rules change.
 */
class StripeBillingProvider implements BillingProvider
{
    public function __construct(private readonly PlanCatalog $plans) {}

    public function checkoutUrl(
        User $payer,
        Project $project,
        Plan $plan,
        string $returnUrl,
        bool $withTrial = false,
    ): string {
        $price = $plan->stripePrice;

        if ($price === null) {
            // Configuration, not a customer problem. A plan marked self-serve
            // with no price behind it would otherwise send somebody to a
            // checkout for nothing.
            throw new RuntimeException("Plan `{$plan->key}` has no Stripe price configured.");
        }
        PlanPrice::verify($plan, Price::retrieve($price, ['api_key' => config('cashier.secret')])->toArray());

        $builder = $payer->newSubscription($project->getKey(), $price);

        // Stripe's own trial is what makes the card-up-front flow work: the
        // subscription is created now and charges nothing, and the first
        // invoice falls due when the free days run out. Somebody who stays does
        // nothing to convert; somebody who leaves cancels before the date.
        //
        // Only when the caller says so. Carrying free days unconditionally gave
        // a fresh window to every customer who cancelled and came back.
        //
        // Stripe requires a trial end at least 48 hours out and Cashier pads to
        // that, so a trial shorter than two days silently becomes two — worth
        // knowing before anybody sets `BILLING_TRIAL_DAYS=1`.
        $builder = $withTrial
            ? $builder->trialDays($this->plans->trialDays())
            : $builder->skipTrial();

        $checkout = $builder
            ->checkout([
                'success_url' => $returnUrl.'?checkout=done',
                'cancel_url' => $returnUrl,
                // So the webhook can tell which project paid without parsing
                // the subscription name, and so a Stripe dashboard row says
                // what it is about.
                'subscription_data' => [
                    'metadata' => [
                        'project_id' => $project->getKey(),
                        'plan' => $plan->key,
                    ],
                ],
                'client_reference_id' => $project->getKey(),
                'allow_promotion_codes' => true,
            ]);

        // Through the session rather than Checkout's `__get` passthrough: the
        // magic accessor is untyped, so static analysis cannot see a `url` on
        // it and neither can anybody reading this.
        return (string) $checkout->asStripeCheckoutSession()->url;
    }

    public function changePlan(User $payer, Project $project, Plan $plan): bool
    {
        $price = $plan->stripePrice;
        // Cashier names a subscription after the project it pays for, which is
        // how one saved card holds several.
        $subscription = $payer->subscription($project->getKey());

        if ($price === null || $subscription === null || ! $subscription->valid()) {
            return false;
        }
        PlanPrice::verify($plan, Price::retrieve($price, ['api_key' => config('cashier.secret')])->toArray());

        $local = ProjectSubscription::query()->where('project_id', $project->getKey())->first();
        if ($local?->stripe_schedule_id !== null && ! $this->cancelPlanChange($payer, $project)) {
            return false;
        }

        // `swap`, not `newSubscription`. The customer keeps one subscription,
        // Stripe prorates the difference, and a trial in progress survives the
        // change — where a second checkout would have started a second charge
        // and a second free window.
        //
        // The metadata is rewritten with it, and that is not housekeeping.
        // `StripeWebhook::planKey()` reads metadata *before* it falls back to
        // the price, because metadata is what a checkout stamps and it survives
        // a subscription being edited in the dashboard. So a swap that changed
        // only the price would emit a `customer.subscription.updated` still
        // naming the old plan — and the local entitlement would sit on the old
        // tier indefinitely while Stripe charged the new amount.
        $subscription->swap($price, [
            'metadata' => [
                'project_id' => $project->getKey(),
                'plan' => $plan->key,
            ],
        ]);

        return true;
    }

    public function schedulePlanChange(User $payer, Project $project, Plan $plan): string
    {
        $local = ProjectSubscription::query()->where('project_id', $project->getKey())->firstOrFail();
        if ($local->stripe_id === null || $local->period_ends_at === null || ! $local->period_ends_at->isFuture() || $plan->stripePrice === null) {
            throw new RuntimeException('A confirmed future renewal and configured price are required.');
        }
        $options = ['api_key' => config('cashier.secret')];
        PlanPrice::verify($plan, Price::retrieve($plan->stripePrice, $options)->toArray());
        $remote = StripeSubscription::retrieve($local->stripe_id, $options);
        if ($remote->customer !== $payer->stripe_id || $remote->status !== 'active' || count($remote->items->data) !== 1 || $remote->cancel_at_period_end) {
            throw new RuntimeException('This subscription needs a billing review before scheduling a change.');
        }
        $end = $remote->items->data[0]->current_period_end ?? ($remote->toArray()['current_period_end'] ?? null);
        if ($end !== $local->period_ends_at->getTimestamp()) {
            throw new RuntimeException('The renewal date changed. Refresh billing before trying again.');
        }
        $remoteSchedule = is_string($remote->schedule) ? $remote->schedule : $remote->schedule?->id;
        if ($remoteSchedule !== null && $local->stripe_schedule_id === null && $local->stripe_schedule_generation !== null) {
            // Recover an accepted create whose response was lost. Only the
            // original idempotency key can prove this is our schedule.
            $recovered = SubscriptionSchedule::create(['from_subscription' => $remote->id], [
                ...$options, 'idempotency_key' => 'avyo-schedule-'.$local->stripe_schedule_generation,
            ]);
            if ($recovered->id === $remoteSchedule) {
                $local->fill(['stripe_schedule_id' => $recovered->id])->save();
            }
        }
        if ($remoteSchedule !== null && $remoteSchedule !== $local->stripe_schedule_id) {
            throw new RuntimeException('Another billing schedule already manages this subscription.');
        }
        if ($remoteSchedule === null && ($local->stripe_schedule_generation === null || $local->stripe_schedule_id !== null)) {
            $local->fill(['stripe_schedule_generation' => (string) Str::ulid(), 'stripe_schedule_id' => null])->save();
        }
        // Held here rather than read back off the row afterwards. A
        // `customer.subscription.updated` carrying `schedule: null` can land
        // while the create below is in flight, and the webhook clears the
        // generation when it does — leaving a schedule whose only proof of
        // ownership, its idempotency key, no longer exists. Writing the pair
        // back together restores it.
        $generation = (string) $local->stripe_schedule_generation;
        $schedule = $remoteSchedule !== null
            ? SubscriptionSchedule::retrieve($remoteSchedule, $options)
            : SubscriptionSchedule::create(['from_subscription' => $remote->id], [
                ...$options, 'idempotency_key' => 'avyo-schedule-'.$generation,
            ]);
        // Persist the provider identity before configuring its phases. A timeout
        // can then be retried against the same schedule, never a second subscription.
        $local->forceFill(['stripe_schedule_id' => $schedule->id, 'stripe_schedule_generation' => $generation])->save();
        $phases = $schedule->phases;
        $current = null;
        foreach ($phases as $phase) {
            if ($phase->start_date <= time() && $phase->end_date > time()) {
                $current = $phase->toArray();
                break;
            }
        }
        if ($current === null) {
            throw new RuntimeException('Stripe returned no current billing phase.');
        }
        // Preserve the current phase's discounts, tax and billing settings.
        $preserved = array_intersect_key($current, array_flip([
            'start_date', 'end_date', 'items', 'metadata', 'discounts', 'default_tax_rates',
            'automatic_tax', 'collection_method', 'default_payment_method', 'invoice_settings',
            'application_fee_percent', 'transfer_data', 'on_behalf_of', 'description',
        ]));
        if (isset($preserved['automatic_tax'])) {
            $preserved['automatic_tax'] = array_intersect_key($preserved['automatic_tax'], array_flip(['enabled', 'liability']));
        }
        $preserved = $this->withoutNulls($preserved);
        $preserved['end_date'] = $end;
        $preserved['items'] = $this->withoutNulls(array_map(static fn (array $item): array => array_intersect_key($item, array_flip(['price', 'quantity', 'tax_rates', 'discounts'])), $current['items']));
        $next = $preserved;
        unset($next['end_date']);
        $next['start_date'] = $end;
        $next['duration'] = ['interval' => 'month', 'interval_count' => 1];
        $next['items'] = [[...$preserved['items'][0], 'price' => $plan->stripePrice, 'quantity' => 1]];
        $next['metadata'] = ['project_id' => $project->getKey(), 'plan' => $plan->key];
        $next['proration_behavior'] = 'none';
        SubscriptionSchedule::update($schedule->id, [
            'end_behavior' => 'release', 'proration_behavior' => 'none',
            'metadata' => ['avyo_project_id' => $project->getKey()],
            'phases' => [$preserved, $next],
        ], [...$options, 'idempotency_key' => 'avyo-phase-'.$schedule->id.'-'.$plan->key]);

        return $schedule->id;
    }

    public function cancelPlanChange(User $payer, Project $project): bool
    {
        $local = ProjectSubscription::query()->where('project_id', $project->getKey())->firstOrFail();
        if ($local->stripe_schedule_id === null) {
            return true;
        }
        $options = ['api_key' => config('cashier.secret')];
        $schedule = SubscriptionSchedule::retrieve($local->stripe_schedule_id, $options);
        if ($schedule->customer !== $payer->stripe_id || ($schedule->subscription !== $local->stripe_id && $schedule->released_subscription !== $local->stripe_id)) {
            throw new RuntimeException('The billing schedule does not belong to this subscription.');
        }
        if ($schedule->status === 'active' || $schedule->status === 'not_started') {
            $schedule->release([], [...$options, 'idempotency_key' => 'avyo-release-'.$schedule->id]);
        }
        $local->fill(['pending_plan' => null, 'pending_plan_at' => null, 'stripe_schedule_id' => null, 'stripe_schedule_generation' => null])->save();

        return true;
    }

    public function extendTrial(User $payer, Project $project, DateTimeInterface $until): bool
    {
        $subscription = $payer->subscription($project->getKey());

        if ($subscription === null || ! $subscription->valid()) {
            return false;
        }

        // Stripe's own trial end, which is the date it will invoice on. Moving
        // only our copy would leave a customer being charged during a window we
        // had told them was free.
        $subscription->extendTrial(Carbon::instance($until));

        return true;
    }

    public function cancelSubscription(User $payer, Project $project): bool
    {
        $local = ProjectSubscription::query()->where('project_id', $project->getKey())->first();
        if ($local?->stripe_id === null) {
            return false;
        }

        // By our own row's id rather than through Cashier's named subscription,
        // which is only there once its webhook has landed — and a subscription
        // this cannot find is one that goes on being billed.
        $options = ['api_key' => config('cashier.secret')];
        $remote = StripeSubscription::retrieve($local->stripe_id, $options);
        if ($remote->customer !== $payer->stripe_id) {
            throw new RuntimeException('The subscription does not belong to this payer.');
        }
        if (in_array($remote->status, ['canceled', 'incomplete_expired'], true)) {
            return true;
        }

        // A scheduled downgrade goes first. Its schedule manages the
        // subscription, and releasing it leaves nothing that could start the
        // subscription again at the next phase. The trade-off: if the cancel
        // below then fails, the pending downgrade is already gone.
        if ($local->stripe_schedule_id !== null) {
            $this->cancelPlanChange($payer, $project);
        }

        // No proration and no final invoice: whatever was paid for the current
        // period stays paid, and nothing further is charged.
        $remote->cancel([], [...$options, 'idempotency_key' => 'avyo-cancel-'.$remote->id]);

        return true;
    }

    public function portalUrl(User $payer, string $returnUrl): string
    {
        return $payer->billingPortalUrl($returnUrl);
    }

    public function subscription(string $stripeId): ?ProviderSubscription
    {
        try {
            $subscription = StripeSubscription::retrieve($stripeId, ['api_key' => config('cashier.secret')]);
        } catch (ApiErrorException $e) {
            // Null rather than a throw, because the one caller is the
            // reconciler and "Stripe has never heard of this" is a finding it
            // exists to make — not an outage.
            Log::warning('Stripe did not return a subscription', [
                'stripe_id' => $stripeId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return new ProviderSubscription(
            id: $subscription->id,
            status: self::statusFrom($subscription->status),
            rawStatus: (string) $subscription->status,
            priceId: $subscription->items->data[0]->price->id ?? null,
            periodStart: self::at($subscription->items->data[0]->current_period_start ?? null),
            periodEnd: self::at($subscription->items->data[0]->current_period_end ?? null),
            trialEnd: self::at($subscription->trial_end),
            canceledAt: self::at($subscription->canceled_at),
        );
    }

    /**
     * Stripe's vocabulary, mapped onto ours, in the one place it may be.
     *
     * Fewer states come out than go in, deliberately: `incomplete`,
     * `incomplete_expired` and `unpaid` all describe the life of an invoice and
     * all answer this application's only question — may this project spend —
     * identically.
     *
     * **The default is the strict one.** A status Stripe adds next year arrives
     * here as an unrecognised string, and the two ways to be wrong about it are
     * not symmetrical: treating an unknown state as entitled gives away service
     * silently and for ever, while treating it as past due stops generation,
     * keeps publishing running, and is visible to the customer within a day.
     */
    public static function statusFrom(string $stripeStatus): BillingStatus
    {
        return match ($stripeStatus) {
            'active' => BillingStatus::Active,
            'trialing' => BillingStatus::Trialing,
            'canceled', 'incomplete_expired' => BillingStatus::Canceled,
            'past_due', 'unpaid', 'incomplete', 'paused' => BillingStatus::PastDue,
            default => self::unknown($stripeStatus),
        };
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string|int, mixed>
     */
    private function withoutNulls(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $result[$key] = is_array($value) ? $this->withoutNulls($value) : $value;
            }
        }

        return $result;
    }

    private static function unknown(string $stripeStatus): BillingStatus
    {
        Log::warning('Unrecognised Stripe subscription status; treated as past due', [
            'stripe_status' => $stripeStatus,
        ]);

        return BillingStatus::PastDue;
    }

    private static function at(mixed $timestamp): ?Carbon
    {
        return is_int($timestamp) && $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
