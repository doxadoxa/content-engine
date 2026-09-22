<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BillingStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Support\Metering\ProjectSpend;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The business, on one screen.
 *
 * The figure worth having here is not MRR — a payment provider's own dashboard
 * shows that better than we can — but **margin per project**, which no generic
 * billing dashboard can compute because it needs both halves: what a customer
 * pays, which Stripe knows, and what they cost us, which only this application
 * does. Every model call and every picture has been metered since §3.4, so this
 * is nearly free to produce and it is the number that decides whether a plan
 * is priced right.
 */
class AdminOverviewController extends Controller
{
    public function __invoke(): Response
    {
        $since = Carbon::now()->startOfMonth();

        $subscriptions = ProjectSubscription::query()->with('project')->get();

        $paying = $subscriptions->filter(
            fn (ProjectSubscription $s): bool => $s->status === BillingStatus::Active && $s->plan !== 'trial',
        );

        /** @var array<string,int> $revenueByCurrency */
        $revenueByCurrency = [];
        foreach ($paying as $subscription) {
            $plan = $subscription->plan();
            $revenueByCurrency[$plan->currency] = ($revenueByCurrency[$plan->currency] ?? 0) + $plan->priceCents;
        }
        ksort($revenueByCurrency);

        // Spend across every tenant, which is the one reading in this
        // application that legitimately spans them — and in two queries rather
        // than two per project, because this screen reads all of them and a
        // loop of `ProjectSpend::total()` is fine at three tenants and a page
        // load at three hundred.
        /** @var list<string> $ids */
        $ids = $subscriptions->pluck('project_id')->values()->all();

        $spends = ProjectSpend::summaries($ids, $since);

        $costMicros = 0;
        $costComplete = true;
        $margins = [];

        foreach ($subscriptions as $subscription) {
            $project = $subscription->project;

            if (! $project instanceof Project) {
                continue;
            }

            // Absent means nothing spent: a project with no rows and a project
            // that cost nothing are the same thing to every reader here.
            $summary = $spends[$project->getKey()] ?? null;
            $spend = $summary['total_micros'] ?? 0;
            $rowComplete = ($summary['completeness'] ?? 'incomplete') === 'complete';
            $costComplete = $costComplete && $rowComplete;
            $costMicros += $spend;

            $priceCents = $subscription->status === BillingStatus::Active && $subscription->plan !== 'trial'
                ? $subscription->plan()->priceCents
                : 0;

            $margins[] = [
                'project_id' => $project->getKey(),
                'name' => $project->name,
                'slug' => $project->slug,
                'plan' => $subscription->plan()->name,
                'status' => $subscription->status->value,
                'price_cents' => $priceCents,
                'currency' => $subscription->plan()->currency,
                'contribution_micros' => $subscription->plan()->currency === 'usd' && $rowComplete ? $priceCents * 10_000 - $spend : null,
                'cost_micros' => $spend,
                'cost_complete' => $rowComplete,
                // The ceiling as a proportion, because "which projects are
                // closest to costing more than they pay" is the question this
                // screen is for and a raw figure buries it.
                'ceiling_micros' => $subscription->plan()->limit('cost_micros'),
            ];
        }

        // Order by one measured unit only; cross-currency margins have no valid rank.
        usort($margins, static fn (array $a, array $b): int => $b['cost_micros'] <=> $a['cost_micros']);
        $contribution = $costComplete && array_diff(array_keys($revenueByCurrency), ['usd']) === []
            ? ($revenueByCurrency['usd'] ?? 0) * 10_000 - $costMicros : null;

        return Inertia::render('admin/overview', [
            'month' => $since->toDateString(),
            'counts' => [
                'projects' => $subscriptions->count(),
                'active' => $paying->count(),
                'trialing' => $subscriptions->where('status', BillingStatus::Trialing)->count(),
                'past_due' => $subscriptions->where('status', BillingStatus::PastDue)->count(),
                'canceled' => $subscriptions->where('status', BillingStatus::Canceled)->count(),
            ],
            'revenue_by_currency' => collect($revenueByCurrency)->map(fn (int $cents, string $currency): array => ['currency' => $currency, 'cents' => $cents])->values()->all(),
            'contribution_micros' => $contribution,
            'cost_micros' => $costMicros,
            'cost_complete' => $costComplete,
            'cost_currency' => 'usd',
            'margins' => array_slice($margins, 0, 20),
            'recent_actions' => AdminAction::query()
                ->with(['actor', 'project'])
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(fn (AdminAction $action): array => [
                    'id' => $action->id,
                    'action' => $action->action,
                    'actor' => $action->actor?->name,
                    'project' => $action->project?->name,
                    'at' => $action->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }
}
