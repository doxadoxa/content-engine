<?php

declare(strict_types=1);

namespace App\Feedback\Followups;

use App\Models\PageChangeBaseline;
use App\Models\PageChangeFollowup;
use App\Models\PageChangeReview;
use App\Models\PagePublication;
use App\Opportunities\PageOutcomeReviews;
use Carbon\CarbonImmutable;

final class ChangeFollowupReport
{
    /** @return array<string, mixed> */
    public function get(): array
    {
        $followups = PageChangeFollowup::query()->orderByDesc('verified_at')->orderBy('days')->limit(100)->get();
        $items = $followups->groupBy('publication_id')->map(function ($group, string $publicationId): array {
            /** @var PageChangeFollowup $first */
            $first = $group->first();
            $baseline = PageChangeBaseline::query()->findOrFail($first->baseline_id);
            $publication = PagePublication::query()->findOrFail($publicationId);
            $periods = $group->map(function (PageChangeFollowup $followup) use ($baseline): array {
                $window = new ObservationWindow(CarbonImmutable::parse($followup->window_from->toDateString(), ObservationWindow::ZONE), CarbonImmutable::parse($followup->window_to->toDateString(), ObservationWindow::ZONE), $followup->days);
                $review = PageChangeReview::query()->where('followup_id', $followup->id)->orderByDesc('id')->first();

                return [
                    'id' => $followup->id, ...$window->toArray(), 'due_on' => $followup->due_on->toDateString(), 'settled_days' => $window->settledDays(),
                    'review_id' => $review?->id, 'current_recoveries' => app(RecoveryConfounders::class)->within($followup->site_page_id, $baseline->pinned_at, $window->to->addDay()->utc()),
                    'status' => $review->status ?? ($window->settledDays() < $window->days ? 'observing' : 'awaiting_data'),
                    'captured_at' => $review?->captured_at->toIso8601String(),
                    'baseline' => $baseline->evidence['periods'][$followup->days] ?? null,
                    'baseline_wider_search' => $baseline->evidence['wider_search'][$followup->days] ?? null,
                    'review' => $review?->evidence,
                    'review_count' => PageChangeReview::query()->where('followup_id', $followup->id)->count(),
                ];
            })->values()->all();

            return [
                'outcome' => app(PageOutcomeReviews::class)->context($publication),
                'publication_id' => $publicationId, 'proposal_id' => $baseline->proposal_id, 'revision_id' => $baseline->revision_id,
                'page_id' => $baseline->site_page_id, 'title' => $baseline->evidence['page']['title'] ?? 'Changed page',
                'url' => $baseline->evidence['page']['url'] ?? null,
                'verified_at' => $first->verified_at->toIso8601String(), 'verification_snapshot_id' => $publication->verification_snapshot_id,
                'baseline_pinned_at' => $baseline->pinned_at->toIso8601String(), 'baseline_stage' => $baseline->evidence['pin_stage'] ?? 'approval',
                'known_confounders' => $baseline->evidence['known_confounders'] ?? [], 'limitations' => $baseline->evidence['limitations'] ?? [],
                'comparison_rule' => $baseline->evidence['comparison_rule'] ?? 'No comparison candidates were pinned.',
                'periods' => $periods,
            ];
        })->values()->all();

        return ['items' => $items, 'limit' => 100, 'total_windows' => PageChangeFollowup::query()->count(),
            'method' => 'Two fixed observation windows begin after the verification day. Search waits three days for settlement. Baselines and each review are retained as recorded, including missing evidence. Changes are associations, not proof of incremental sales.'];
    }
}
