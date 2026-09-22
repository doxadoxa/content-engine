<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\PageProposalReview;
use App\Models\Project;
use App\Models\ServiceEffortEntry;
use App\Support\Metering\ProjectSpend;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DeliveryEconomics
{
    /** @return array<string, mixed> */
    public function report(Project $project, Carbon $start, Carbon $end): array
    {
        return app(CurrentProject::class)->run($project, function () use ($project, $start, $end): array {
            $entries = ServiceEffortEntry::query()->where('happened_at', '>=', $start->toIso8601String())
                ->where('happened_at', '<', $end->toIso8601String())->whereDoesntHave('replacement')->get();
            $supportMicros = 0;
            $unpricedMinutes = 0;
            $categories = [];
            foreach ($entries as $entry) {
                $categories[$entry->category] = ($categories[$entry->category] ?? 0) + $entry->minutes;
                if ($entry->hourly_usd_cents === null) {
                    $unpricedMinutes += $entry->minutes;
                } else {
                    $supportMicros += (int) round($entry->minutes * $entry->hourly_usd_cents * 10000 / 60);
                }
            }
            $reviews = PageProposalReview::query()->where('created_at', '>=', $start->toIso8601String())->where('created_at', '<', $end->toIso8601String());
            $spend = ProjectSpend::for($project, $start, $end)->toArray();
            $offer = app(Entitlements::class)->for($project)->plan;
            if ($offer === null || ! $offer->selfServe || $offer->currency !== 'usd') {
                $offer = app(PlanCatalog::class)->defaultPlan();
            }

            return [
                'start' => $start->toIso8601String(), 'end_exclusive' => $end->toIso8601String(),
                'provider' => $spend,
                'support' => ['entries' => $entries->count(), 'minutes' => $entries->sum('minutes'), 'priced_micros' => $supportMicros, 'unpriced_minutes' => $unpricedMinutes, 'categories' => $categories],
                'owner_review' => ['active_seconds' => (int) $reviews->clone()->sum('active_seconds'), 'decisions' => $reviews->clone()->select('action', DB::raw('count(*) as count'))->groupBy('action')->pluck('count', 'action')->all()],
                'improvements' => (int) DB::table('page_improvement_allowances')->where('project_id', $project->id)->where('accepted_at', '>=', $start->toIso8601String())->where('accepted_at', '<', $end->toIso8601String())->sum('units'),
                // A package scenario is not a paid invoice or actual profit.
                // Null remains null until human cost has some priced evidence.
                'offer' => ['name' => $offer->name, 'articles' => $offer->limit('articles'), 'price_cents' => $offer->priceCents, 'currency' => $offer->currency, 'allowance' => $offer->limit('page_improvements'),
                    'remaining_after_recorded_costs_micros' => $entries->isEmpty() || $unpricedMinutes > 0 || $spend['completeness'] !== 'complete' ? null : $offer->priceCents * 10000 - $supportMicros - $spend['total_micros']],
                'history' => ServiceEffortEntry::query()->with('replacement')->latest()->limit(100)->get()->map(fn (ServiceEffortEntry $entry): array => [
                    'id' => $entry->id, 'category' => $entry->category, 'minutes' => $entry->minutes, 'hourly_usd_cents' => $entry->hourly_usd_cents,
                    'happened_at' => $entry->happened_at->toIso8601String(), 'note' => $entry->note, 'supersedes_id' => $entry->supersedes_id,
                    'replaced_by' => $entry->replacement?->id,
                ])->all(),
            ];
        });
    }
}
