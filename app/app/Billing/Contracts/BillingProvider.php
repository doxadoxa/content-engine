<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

use App\Ai\Contracts\ConversationGateway;
use App\Ai\Contracts\ModelGateway;
use App\Billing\Plan;
use App\Billing\Subscriptions;
use App\Models\Project;
use App\Models\User;
use DateTimeInterface;

/**
 * The only path this application takes to a payment provider.
 *
 * Held to the same two rules as {@see ModelGateway} and
 * {@see ConversationGateway}, which is why it exists at all
 * rather than the controllers calling Cashier directly:
 *
 * - It is the **only** door, so the test suite binds a fake over it and no test
 *   reaches Stripe. A billing suite that needs the network is a billing suite
 *   that gets skipped, and this is the subsystem where an untested branch costs
 *   money in both directions.
 * - It speaks this application's vocabulary — a project, a plan, a payer —
 *   rather than the provider's. Everything downstream of a webhook already
 *   works in those terms (see {@see Subscriptions}), and a
 *   provider's price ids leaking past this line is how a second billing model
 *   grows inside the first.
 *
 * Deliberately small. Checkout and the portal are hosted by Stripe, so there is
 * no card form, no payment-method management and no invoice rendering here to
 * abstract — the whole surface is "send them somewhere" and "tell me what you
 * know about this subscription".
 */
interface BillingProvider
{
    /**
     * Where to send somebody to start paying for a project.
     *
     * Returns an absolute URL. Hosted Checkout rather than a card form on our
     * side: no card data touches this application, tax and promotion codes come
     * free, and the three-D-secure dance is somebody else's problem.
     */
    public function checkoutUrl(User $payer, Project $project, Plan $plan, string $returnUrl): string;

    /**
     * Move a subscription that already exists onto another plan.
     *
     * Not a second checkout. A checkout opens a *new* recurring subscription,
     * so an existing customer choosing another plan would end up with two of
     * them at the provider and be billed for both — while the one local row
     * followed whichever webhook happened to arrive last. A plan change is a
     * change to the thing already being charged, prorated by the provider.
     *
     * Returns false when there is nothing to change, so the caller can fall
     * back to opening a checkout.
     */
    public function changePlan(User $payer, Project $project, Plan $plan): bool;

    /**
     * Move the provider's own trial end.
     *
     * Stripe owns the date it will invoice on. Extending only our copy of it
     * produces a project that believes it is inside a free window while the
     * card is charged on the original date — which is the worst possible
     * version of "we gave you a few more days".
     *
     * Returns false when there is no provider-backed subscription to extend,
     * so the caller can move its own dates instead.
     */
    public function extendTrial(User $payer, Project $project, DateTimeInterface $until): bool;

    /**
     * Where to send somebody to change their card, their plan, or their mind.
     *
     * The Billing Portal, for the same reason: plan changes, card updates,
     * invoice history and cancellation are six screens we would otherwise
     * build, and Stripe keeps them correct as its own rules change.
     */
    public function portalUrl(User $payer, string $returnUrl): string;

    /**
     * What the provider currently believes about a subscription.
     *
     * Null when it has never heard of it. This is what the reconciler compares
     * our projection against — a missed webhook silently entitles or
     * disentitles a project, and one will be missed.
     */
    public function subscription(string $stripeId): ?ProviderSubscription;
}
