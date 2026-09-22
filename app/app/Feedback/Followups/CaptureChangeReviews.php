<?php

declare(strict_types=1);

namespace App\Feedback\Followups;

use App\Models\PageChangeBaseline;
use App\Models\PageChangeFollowup;
use App\Models\PageChangeReview;
use App\Models\PagePublication;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\PurchaseSource;
use App\Models\SitePage;
use App\Purchases\PurchaseReport;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CaptureChangeReviews
{
    public function __construct(private readonly CurrentProject $current, private readonly SearchWindowEvidence $search, private readonly PurchaseReport $purchases, private readonly StartChangeFollowups $starts) {}

    public function refresh(Project $project): void
    {
        $this->current->run($project, function () use ($project): void {
            // Recover an after-commit notification interrupted after public verification was persisted.
            PagePublication::query()->where('status', 'verified')->whereNotNull('verified_at')->whereNotNull('verification_snapshot_id')
                ->whereNotIn('id', PageChangeFollowup::query()->select('publication_id')->groupBy('publication_id')->havingRaw('count(*) >= 2'))
                ->orderBy('id')->chunkById(50, function ($publications) use ($project): void {
                    foreach ($publications as $publication) {
                        $this->starts->start($project->id, $publication->id);
                    }
                });
            PageChangeFollowup::query()->where('due_on', '<=', CarbonImmutable::today(ObservationWindow::ZONE)->toDateString())
                ->orderBy('id')->chunkById(50, function ($followups): void {
                    foreach ($followups as $followup) {
                        $this->capture($followup);
                    }
                });
        });
    }

    /** @phpstan-impure */
    public function capture(PageChangeFollowup $followup): ?PageChangeReview
    {
        return $this->current->run($followup->project_id, fn (): ?PageChangeReview => DB::transaction(function () use ($followup): ?PageChangeReview {
            $followup = PageChangeFollowup::query()->whereKey($followup->id)->lockForUpdate()->firstOrFail();
            // Date casts use the application zone. Keep their calendar dates when converting.
            $window = new ObservationWindow(CarbonImmutable::parse($followup->window_from->toDateString(), ObservationWindow::ZONE), CarbonImmutable::parse($followup->window_to->toDateString(), ObservationWindow::ZONE), $followup->days);
            if ($window->settledDays() < $window->days) {
                return null;
            }
            $baseline = PageChangeBaseline::query()->findOrFail($followup->baseline_id);
            $page = SitePage::query()->findOrFail($followup->site_page_id);
            $source = $baseline->purchase_source_id === null ? null : PurchaseSource::query()->find($baseline->purchase_source_id);
            $primary = PurchaseSource::query()->where('is_primary', true)->first();
            $before = $baseline->evidence['periods'][$followup->days] ?? [];
            $search = $this->search->capture([$page->id], $window);
            $purchaseWindow = $this->purchases->summarize($source, $window->from, $window->to->addDay(), $page->id);
            $baselineWindow = ObservationWindow::baseline($baseline->pinned_at, $followup->days);
            $reconciledBefore = $this->purchases->summarize($source, $baselineWindow->from, $baselineWindow->to->addDay(), $page->id);
            $sourceSame = $source !== null && $source->id === $primary?->id;
            $trackingSame = $source !== null && ($before['purchases']['tracking_started_at'] ?? null) === $source->tracking_started_at?->toIso8601String()
                && ($before['purchases']['source_kind'] ?? null) === $source->kind;
            $cohort = $baseline->evidence['cohort'] ?? [];
            $cohortIds = array_values(array_filter(array_column($cohort, 'page_id'), 'is_string'));
            $comparisons = [];
            foreach (($baseline->evidence['comparison_candidates'] ?? []) as $candidate) {
                $comparisons[] = $this->comparison($candidate, $baseline, $window);
            }
            $changes = $this->interveningChanges($page->id, $baseline->pinned_at, $window->to->addDay()->utc(), $followup->publication_id);
            $publication = PagePublication::query()->whereKey($followup->publication_id)->firstOrFail();
            $recoveries = app(RecoveryConfounders::class)->within($page->id, $baseline->pinned_at, $window->to->addDay()->utc());
            $verifiedSnapshot = PageSnapshot::query()->whereKey($publication->verification_snapshot_id)->first();
            $externalChange = $verifiedSnapshot === null || PageSnapshot::query()->where('site_page_id', $page->id)->where('source_kind', 'public')
                ->where('captured_at', '<', $window->to->addDay()->utc())
                ->where(fn ($query) => $query->where('captured_at', '>', $followup->verified_at->utc())
                    ->orWhere(fn ($sameInstant) => $sameInstant->where('captured_at', $followup->verified_at->utc())->where('id', '>', $verifiedSnapshot->id)))
                ->where('content_hash', '!=', $verifiedSnapshot->content_hash)->exists();
            $identitySame = ($baseline->evidence['page']['url'] ?? null) === ($page->canonical_url ?? $page->url)
                && ($baseline->evidence['page']['locale'] ?? null) === $page->locale;
            $searchSourceSame = ($before['search']['property_consistent'] ?? false) && $search['property_consistent']
                && ($before['search']['property'] ?? null) === $search['property'];
            $limitations = array_merge($baseline->evidence['limitations'] ?? [], [
                'These are observations after a change, not a causal uplift estimate.',
                'Unrecorded campaigns, seasonality, consent gaps, outages or site changes may explain differences.',
                'Unchanged comparisons only mean that the available public snapshots agree and no intervening change was recorded.',
                'Purchase snapshots use the same source and sale definition. Later corrections and refunds can restate the baseline; both versions are retained.',
            ]);
            if (! $sourceSame || ! $trackingSame || ! $source->is_enabled) {
                $limitations[] = 'The pinned purchase source is missing, paused, no longer primary or its tracking settings changed; purchase comparison is not reliable.';
            }
            if (! $identitySame || $page->tracked_at === null) {
                $limitations[] = 'Page identity or tracking changed after approval; search comparison is not reliable.';
            }
            if (! $searchSourceSame) {
                $limitations[] = 'The Search Console property is unknown, mixed or changed between windows; search comparison is not reliable.';
            }
            if ($changes !== []) {
                $limitations[] = 'Another publication affected this page during the comparison interval.';
            }
            if ($recoveries !== []) {
                $limitations[] = 'A recovery, or an unresolved recovery attempt, affected this comparison interval. The original change cannot be isolated.';
            }
            if ($externalChange) {
                $limitations[] = 'Public evidence changed after publication, or the verified snapshot is missing; the change cannot be isolated.';
            }
            $status = $search['status'] === 'recorded' ? ($search['sparse'] ? 'sparse' : 'observed') : $search['status'];
            $evidence = [
                'window' => $window->toArray(), 'search' => $search, 'purchases' => $purchaseWindow,
                'baseline_reconciled_purchases' => $reconciledBefore,
                'all_recorded_purchases' => $this->purchases->summarize($source, $window->from, $window->to->addDay()),
                'purchase_comparison_available' => $identitySame && ! $externalChange && $changes === [] && $recoveries === [] && $source !== null && $sourceSame && $trackingSame && $source->is_enabled && ($before['purchases']['status'] ?? null) === 'recorded' && $purchaseWindow['status'] === 'recorded'
                    && ($before['purchases']['currencies'] ?? []) !== [] && $purchaseWindow['currencies'] !== []
                    && $source->tracking_started_at !== null && $source->tracking_started_at->lessThanOrEqualTo($baselineWindow->from),
                'search_comparison_available' => $searchSourceSame && $identitySame && $page->tracked_at !== null && ! $externalChange && $changes === [] && $recoveries === [] && $search['status'] === 'recorded' && ! $search['sparse']
                    && ($before['search']['status'] ?? null) === 'recorded' && ! ($before['search']['sparse'] ?? true),
                'comparisons' => $comparisons, 'wider_search' => $this->search->capture($cohortIds, $window),
                'wider_scope' => 'Other pages tracked at approval, using the same fixed cohort; this is not a whole-site total.',
                'intervening_publications' => $changes, 'recoveries' => $recoveries, 'later_public_content_changed' => $externalChange,
                'known_confounders' => $baseline->evidence['known_confounders'] ?? [], 'limitations' => $limitations,
            ];
            $hash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));

            return PageChangeReview::query()->firstOrCreate(['followup_id' => $followup->id, 'evidence_hash' => $hash], [
                'captured_at' => CarbonImmutable::now(), 'status' => $status, 'evidence' => $evidence,
            ]);
        }));
    }

    /** @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function comparison(array $candidate, PageChangeBaseline $baseline, ObservationWindow $window): array
    {
        $page = SitePage::query()->whereKey($candidate['page_id'])->first();
        $reason = null;
        $proof = null;
        if ($page === null || $page->tracked_at === null || ($page->canonical_url ?? $page->url) !== $candidate['url'] || $page->locale !== $candidate['locale'] || $page->page_kind?->value !== $candidate['page_kind']) {
            $reason = 'The comparison page identity or tracking changed.';
        } else {
            $proof = PageSnapshot::query()->where('site_page_id', $page->id)->where('source_kind', 'public')
                ->where('captured_at', '>=', $window->to->addDay()->utc())->where('captured_at', '<=', CarbonImmutable::now())->orderBy('captured_at')->orderBy('id')->first();
            if ($proof === null) {
                $reason = 'No public snapshot after this observation window verifies unchanged content.';
            } elseif ($proof->content_hash !== $candidate['content_hash']
                || ($proof->metadata['canonical_url'] ?? null) !== $candidate['url'] || ($proof->metadata['locale'] ?? null) !== $candidate['locale']) {
                $reason = 'Later public evidence does not match the pinned page.';
            } elseif (PageSnapshot::query()->where('site_page_id', $page->id)->where('source_kind', 'public')
                ->whereBetween('captured_at', [$baseline->pinned_at->utc(), $proof->captured_at->copy()->utc()])->where('content_hash', '!=', $candidate['content_hash'])->exists()
                || $this->interveningChanges($page->id, $baseline->pinned_at, $window->to->addDay()->utc()) !== []
                || app(RecoveryConfounders::class)->within($page->id, $baseline->pinned_at, $window->to->addDay()->utc()) !== []) {
                $reason = 'An intervening page change was recorded.';
            }
        }
        $search = $page === null ? null : $this->search->capture([$page->id], $window);

        return [
            'page_id' => $candidate['page_id'], 'title' => $candidate['title'], 'url' => $candidate['url'],
            'status' => $reason === null ? 'observed_unchanged' : 'unavailable', 'reason' => $reason,
            'verification_snapshot_id' => $proof?->id, 'baseline' => $candidate['search'][$window->days] ?? null, 'followup' => $search,
            'comparison_available' => $reason === null && ($candidate['search'][$window->days]['property_consistent'] ?? false) && ($search['property_consistent'] ?? false)
                && ($candidate['search'][$window->days]['property'] ?? null) === ($search['property'] ?? null) && ($search['status'] ?? null) === 'recorded' && ! ($search['sparse'] ?? true)
                && ($candidate['search'][$window->days]['status'] ?? null) === 'recorded' && ! ($candidate['search'][$window->days]['sparse'] ?? true),
        ];
    }

    /** @return list<string> */
    private function interveningChanges(string $pageId, CarbonImmutable $from, CarbonImmutable $to, ?string $except = null): array
    {
        $ids = PagePublication::query()->where('site_page_id', $pageId)->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
            ->where(fn ($query) => $query
                ->where(fn ($applied) => $applied->where('applied_at', '>=', $from->utc())->where('applied_at', '<', $to->utc()))
                ->orWhere(fn ($verified) => $verified->where('verified_at', '>=', $from->utc())->where('verified_at', '<', $to->utc())))
            ->orderBy('id')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        return array_values($ids);
    }
}
