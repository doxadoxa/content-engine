<?php

declare(strict_types=1);

namespace App\Feedback\Followups;

use App\Models\PageChangeBaseline;
use App\Models\PageProposal;
use App\Models\PageProposalRevision;
use App\Models\PageSnapshot;
use App\Models\PurchaseSource;
use App\Models\SitePage;
use App\Purchases\PurchaseReport;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PinChangeBaseline
{
    public function __construct(private readonly CurrentProject $current, private readonly SearchWindowEvidence $search, private readonly PurchaseReport $purchases) {}

    public function capture(string $projectId, string $proposalId, string $revisionId): PageChangeBaseline
    {
        return $this->current->run($projectId, fn (): PageChangeBaseline => DB::transaction(function () use ($proposalId, $revisionId): PageChangeBaseline {
            $proposal = PageProposal::query()->whereKey($proposalId)->lockForUpdate()->firstOrFail();
            $revision = PageProposalRevision::query()->whereKey($revisionId)->where('proposal_id', $proposalId)->firstOrFail();
            if ($proposal->approved_revision_id !== $revision->id) {
                throw new LogicException('Only an accepted revision can pin its baseline.');
            }
            $existing = PageChangeBaseline::query()->where('revision_id', $revisionId)->first();
            if ($existing !== null) {
                return $existing;
            }
            $page = SitePage::query()->whereKey($proposal->site_page_id)->firstOrFail();
            $snapshot = PageSnapshot::query()->whereKey($revision->source_snapshot_id)->where('site_page_id', $page->id)->firstOrFail();
            $source = PurchaseSource::query()->where('is_primary', true)->first();
            $at = CarbonImmutable::now();
            $periods = [];
            foreach ([14, 28] as $days) {
                $periods[$days] = $this->period($page->id, $source, ObservationWindow::baseline($at, $days), $at);
            }
            $cohort = [];
            $candidates = [];
            $targetImpressions = $periods[28]['search']['impressions'];
            foreach (SitePage::query()->tracked()->where('id', '!=', $page->id)->orderBy('id')->get() as $other) {
                $public = $other->snapshots()->where('source_kind', 'public')->where('captured_at', '<=', $at)->first();
                $search = [];
                foreach ([14, 28] as $days) {
                    $search[$days] = $this->search->capture([$other->id], ObservationWindow::baseline($at, $days), $at);
                }
                $item = [
                    'page_id' => $other->id, 'url' => $other->canonical_url ?? $other->url, 'title' => $other->title,
                    'locale' => $other->locale, 'page_kind' => $other->page_kind?->value,
                    'snapshot_id' => $public?->id, 'content_hash' => $public?->content_hash, 'snapshot_at' => $public?->captured_at->toIso8601String(),
                    'search' => $search,
                ];
                $cohort[] = $item;
                $volume = $search[28]['impressions'];
                if ($public !== null && $public->captured_at->greaterThanOrEqualTo($at->subDays(7))
                    && $page->locale !== null && $other->locale === $page->locale
                    && $page->page_kind !== null && $other->page_kind === $page->page_kind
                    && $targetImpressions !== null && $targetImpressions > 0 && $volume !== null
                    && $search[28]['status'] === 'recorded' && $volume >= $targetImpressions / 2 && $volume <= $targetImpressions * 2) {
                    $candidates[] = [...$item, 'volume_distance' => abs($volume - $targetImpressions)];
                }
            }
            usort($candidates, static fn (array $a, array $b): int => ($a['volume_distance'] <=> $b['volume_distance']) ?: strcmp($a['page_id'], $b['page_id']));
            $cohortIds = array_column($cohort, 'page_id');
            $wider = [];
            foreach ([14, 28] as $days) {
                $wider[$days] = $this->search->capture($cohortIds, ObservationWindow::baseline($at, $days), $at);
            }
            $confounders = $revision->measurement_plan['known_confounders'] ?? [];

            return PageChangeBaseline::query()->create([
                'proposal_id' => $proposalId, 'revision_id' => $revisionId, 'site_page_id' => $page->id,
                'source_snapshot_id' => $snapshot->id, 'purchase_source_id' => $source?->id, 'pinned_at' => $at,
                'evidence' => [
                    'pin_stage' => 'approval',
                    'page' => ['url' => $page->canonical_url ?? $page->url, 'title' => $page->title, 'locale' => $page->locale, 'page_kind' => $page->page_kind?->value],
                    'periods' => $periods, 'cohort' => $cohort, 'wider_search' => $wider, 'comparison_candidates' => array_slice($candidates, 0, 3),
                    'comparison_rule' => 'Same recorded locale and page kind; baseline impressions between half and twice the changed page; public snapshot no older than seven days. Later unchanged evidence is still required.',
                    'known_confounders' => is_array($confounders) ? array_values(array_filter($confounders, 'is_string')) : [],
                    'purchase_definition' => 'paid-order-v1: one reconciled transaction; paid and fully refunded sale cohorts reported separately from cancelled or partial records.',
                    'attribution_definition' => 'Pinned purchase source and its recorded landing-page identity; unknown or manual attribution is never inferred.',
                    'limitations' => ['Approval evidence is frozen, including missing historical tracking.', 'Campaigns, seasonality, consent gaps, outages and changes outside Avyo may affect observations.', 'The wider context covers other tracked pages only; it is not a whole-site census.'],
                ],
            ]);
        }));
    }

    /** @return array<string, mixed> */
    private function period(string $pageId, ?PurchaseSource $source, ObservationWindow $window, CarbonImmutable $at): array
    {
        return [
            'search' => $this->search->capture([$pageId], $window, $at),
            'purchases' => $this->purchases->summarize($source, $window->from, $window->to->addDay(), $pageId),
            'all_recorded_purchases' => $this->purchases->summarize($source, $window->from, $window->to->addDay()),
        ];
    }
}
