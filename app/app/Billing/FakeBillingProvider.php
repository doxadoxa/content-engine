<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Contracts\ProviderSubscription;
use App\Models\Project;
use App\Models\User;

/**
 * Stripe, without the network.
 *
 * Bound over {@see BillingProvider} in the test
 * environment for the same reason the model and conversation gateways have
 * fakes: the suite must never reach a provider, in any phase, for any test.
 * It matters more here than anywhere else in this application — a billing suite
 * that needs credentials is a billing suite that gets skipped, and this is the
 * one subsystem where an untested branch costs money in both directions.
 *
 * It records what it was asked for, so a test can assert that a checkout was
 * started for the right project on the right plan, which is the half that
 * matters and the half a stub returning a fixed URL would not prove.
 */
class FakeBillingProvider implements BillingProvider
{
    /** @var list<array{payer: int, project: string, plan: string, return_url: string}> */
    public array $checkouts = [];

    /** @var list<array{payer: int, return_url: string}> */
    public array $portals = [];

    /** @var array<string, ProviderSubscription> */
    private array $subscriptions = [];

    /** What Stripe will say about this subscription when the reconciler asks. */
    public function willReport(ProviderSubscription $subscription): self
    {
        $this->subscriptions[$subscription->id] = $subscription;

        return $this;
    }

    public function checkoutUrl(User $payer, Project $project, Plan $plan, string $returnUrl): string
    {
        $this->checkouts[] = [
            'payer' => (int) $payer->getKey(),
            'project' => $project->getKey(),
            'plan' => $plan->key,
            'return_url' => $returnUrl,
        ];

        return 'https://checkout.stripe.test/'.$plan->key.'/'.$project->getKey();
    }

    public function portalUrl(User $payer, string $returnUrl): string
    {
        $this->portals[] = ['payer' => (int) $payer->getKey(), 'return_url' => $returnUrl];

        return 'https://portal.stripe.test/'.$payer->getKey();
    }

    public function subscription(string $stripeId): ?ProviderSubscription
    {
        return $this->subscriptions[$stripeId] ?? null;
    }
}
