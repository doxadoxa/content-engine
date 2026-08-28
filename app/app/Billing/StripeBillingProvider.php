<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Contracts\ProviderSubscription;
use App\Enums\BillingStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription as StripeSubscription;

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

    public function checkoutUrl(User $payer, Project $project, Plan $plan, string $returnUrl): string
    {
        $price = $plan->stripePrice;

        if ($price === null) {
            // Configuration, not a customer problem. A plan marked self-serve
            // with no price behind it would otherwise send somebody to a
            // checkout for nothing.
            throw new RuntimeException("Plan `{$plan->key}` has no Stripe price configured.");
        }

        $checkout = $payer
            ->newSubscription($project->getKey(), $price)
            // Stripe's own trial, which is what makes the card-up-front flow
            // work: the subscription is created now and charges nothing, and
            // the first invoice falls due when the free days run out. Somebody
            // who stays does nothing to convert; somebody who leaves cancels
            // before the date.
            //
            // Stripe requires a trial end at least 48 hours out and Cashier
            // pads to that, so a trial shorter than two days silently becomes
            // two — which is worth knowing before anybody sets
            // `BILLING_TRIAL_DAYS=1`.
            ->trialDays($this->plans->trialDays())
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
                        'plan_version' => (string) $plan->version,
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
                'plan_version' => (string) $plan->version,
            ],
        ]);

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
