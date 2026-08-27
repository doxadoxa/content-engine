<?php

declare(strict_types=1);

namespace App\Billing\Contracts;

use App\Ai\Contracts\ConversationGateway;
use App\Ai\Contracts\ModelGateway;
use App\Billing\Plan;
use App\Billing\Subscriptions;
use App\Models\Project;
use App\Models\User;

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
