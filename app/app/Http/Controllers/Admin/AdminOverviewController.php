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

        $revenueCents = $paying->sum(fn (ProjectSubscription $s): int => $s->plan()->priceCents);

        // Spend across every tenant, which is the one reading in this
        // application that legitimately spans them.
        $costMicros = 0;
        $margins = [];

        foreach ($subscriptions as $subscription) {
            $project = $subscription->project;

            if (! $project instanceof Project) {
                continue;
            }

            $spend = ProjectSpend::total($project, $since);
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
                'cost_micros' => $spend,
                // The ceiling as a proportion, because "which projects are
                // closest to costing more than they pay" is the question this
                // screen is for and a raw figure buries it.
                'ceiling_micros' => $subscription->plan()->limit('cost_micros'),
            ];
        }

        // Worst margin first: the projects that need looking at are the ones
        // eating a plan they are not paying enough for.
        usort($margins, static fn (array $a, array $b): int => ($b['cost_micros'] - $b['price_cents'] * 10_000)
            <=> ($a['cost_micros'] - $a['price_cents'] * 10_000));

        return Inertia::render('admin/overview', [
            'month' => $since->toDateString(),
            'counts' => [
                'projects' => $subscriptions->count(),
                'active' => $paying->count(),
                'trialing' => $subscriptions->where('status', BillingStatus::Trialing)->count(),
                'past_due' => $subscriptions->where('status', BillingStatus::PastDue)->count(),
                'canceled' => $subscriptions->where('status', BillingStatus::Canceled)->count(),
            ],
            'revenue_cents' => $revenueCents,
            'cost_micros' => $costMicros,
            'currency' => (string) config('billing.currency', 'eur'),
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
