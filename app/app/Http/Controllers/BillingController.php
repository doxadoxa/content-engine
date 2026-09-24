<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\PlanChanges;
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
        private readonly PlanChanges $changes,
    ) {}

    public function __invoke(): Response
    {
        $project = $this->current->get();

        abort_unless($project instanceof Project, 404);

        $entitlement = $this->entitlements->for($project);

        return Inertia::render('billing/index', [
            'entitlement' => $entitlement->toArray(),
            'currency' => $entitlement->plan->currency ?? 'usd',
            'trial_days' => $this->plans->trialDays(),
            'subscription_details' => $entitlement->subscription === null ? null : [
                'period_started_at' => $entitlement->subscription->periodStart()->toIso8601String(),
                'limits' => $entitlement->plan === null ? [] : $this->readableLimits($entitlement->plan),
            ],
            'pending_change' => $entitlement->subscription?->pending_plan === null ? null : [
                'name' => $this->plans->get($entitlement->subscription->pending_plan)->name,
                'effective_at' => $entitlement->subscription->pending_plan_at?->toIso8601String(),
            ],

            // Whether to draw the buttons at all. The routes behind them are
            // owner-only, so an operator shown a "Choose Growth" button would
            // be shown a 403 for pressing it — a control that is not allowed to
            // work should not be drawn rather than drawn and refused.
            'can_pay' => $this->isOwner($project),

            // Nothing to manage until there is something at the provider. A
            // trial and a comped plan have no
            // portal behind them, and sending somebody to one would land them
            // on a Stripe error.
            'has_provider' => $entitlement->subscription?->stripe_id !== null,

            // Only what somebody can buy: a "Choose" button under the preview or
            // the trial would promise a checkout that does not exist.
            'plans' => array_map(
                fn (Plan $plan): array => [
                    ...$plan->toArray(),
                    'limits' => $this->readableLimits($plan),
                    'change' => $this->changes->preview($entitlement->subscription, $plan),
                    'ai_frequency_days' => $plan->limit('ai_frequency_days'),
                    'ai_questions' => $plan->limit('ai_questions'),
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

        foreach ([Metric::Articles, Metric::ContentPlans, Metric::PageImprovements, Metric::SiteAudits, Metric::AssistantTurns] as $metric) {
            if (! array_key_exists($metric->value, $plan->limits())) {
                continue;
            }
            $rows[] = [
                'key' => $metric->value,
                'label' => ucfirst($metric->label()),
                'value' => $plan->limit($metric->value),
            ];
        }

        $rows[] = ['key' => 'ai_answers', 'label' => 'AI answer attempts', 'value' => $plan->limit('ai_answers')];

        foreach (['tracked_pages' => 'Monitored pages', 'locales' => 'Languages', 'seats' => 'Team members', 'channels' => 'Website connections'] as $key => $label) {
            if (! array_key_exists($key, $plan->limits())) {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $plan->limit($key)];
        }

        return $rows;
    }
}
