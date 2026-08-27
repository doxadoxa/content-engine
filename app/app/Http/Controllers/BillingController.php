<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What this project is on, what it has used, and what else there is.
 *
 * One screen rather than a paywall and a settings page. They would show the
 * same four things to the same person, and which of the two somebody saw would
 * depend on whether their trial had run out — so the moment the screen matters
 * most is the moment they would be meeting it for the first time.
 *
 * Readable by any member rather than by owners only, unlike the cost screen
 * beside it. An operator who has run out of articles needs to be able to find
 * out why without asking the account holder; the numbers here are quotas rather
 * than money, and none of them is a figure about us.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly CurrentProject $current,
        private readonly Entitlements $entitlements,
        private readonly PlanCatalog $plans,
    ) {}

    public function __invoke(): Response
    {
        $project = $this->current->get();

        abort_unless($project instanceof Project, 404);

        $entitlement = $this->entitlements->for($project);

        return Inertia::render('billing/index', [
            'entitlement' => $entitlement->toArray(),
            'currency' => (string) config('billing.currency', 'eur'),
            'trial_days' => $this->plans->trialDays(),

            // Whether to draw the buttons at all. The routes behind them are
            // owner-only, so an operator shown a "Choose Medium" button would
            // be shown a 403 for pressing it — a control that is not allowed to
            // work should not be drawn rather than drawn and refused.
            'can_pay' => $this->isOwner($project),

            // Nothing to manage until there is something at the provider. A
            // trial, a comped plan and a hand-assigned Enterprise deal have no
            // portal behind them, and sending somebody to one would land them
            // on a Stripe error.
            'has_provider' => $entitlement->subscription?->stripe_id !== null,

            // Only what somebody can buy without talking to us. Enterprise is a
            // conversation and a custom price; putting a "Choose" button under
            // it would promise a checkout that does not exist.
            'plans' => array_map(
                fn (Plan $plan): array => [
                    ...$plan->toArray(),
                    'limits' => $this->readableLimits($plan),
                    'current' => $plan->key === $entitlement->plan?->key,
                ],
                $this->plans->selfServe(),
            ),
        ]);
    }

    private function isOwner(Project $project): bool
    {
        $user = request()->user();

        if (! $user instanceof User) {
            return false;
        }

        $membership = $user->projects()->whereKey($project->getKey())->first();

        return $membership?->getAttribute('pivot')?->getAttribute('role') === 'owner';
    }

    /**
     * The limits worth putting in front of somebody, in the order they matter.
     *
     * The cost ceiling is deliberately absent and must stay absent. It is the
     * one limit a customer was never sold, and a row on a pricing table for a
     * number nobody mentioned reads as a catch — which it is not, but a
     * plan card is the wrong place to explain that.
     *
     * @return list<array{key: string, label: string, value: int|null}>
     */
    private function readableLimits(Plan $plan): array
    {
        $rows = [];

        foreach (Metric::cases() as $metric) {
            $rows[] = [
                'key' => $metric->value,
                'label' => ucfirst($metric->label()),
                'value' => $plan->limit($metric->value),
            ];
        }

        foreach (['locales' => 'Languages', 'seats' => 'Seats', 'channels' => 'Publishing channels'] as $key => $label) {
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $plan->limit($key)];
        }

        return $rows;
    }
}
