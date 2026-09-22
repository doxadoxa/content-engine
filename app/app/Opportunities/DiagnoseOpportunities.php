<?php

declare(strict_types=1);

namespace App\Opportunities;

use App\Enums\SitePageKind;
use App\Feedback\Measurements\PagePerformance;
use App\Models\BusinessFact;
use App\Models\PageOpportunity;
use App\Models\PageOpportunityScan;
use App\Models\Project;
use App\Models\SitePage;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class DiagnoseOpportunities
{
    public function __construct(private readonly CurrentProject $current, private readonly PagePerformance $performance, private readonly OpportunityText $text, private readonly ExistingPageOverlap $overlap) {}

    /** Re-reading source metrics must not mutate evidence already sent for review. */
    public function refresh(Project $project): int
    {
        return $this->current->run($project, fn () => DB::transaction(function () use ($project): int {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $report = $this->performance->forProject($project);
            /** @var list<array<string, mixed>> $pageMeasurements */
            $pageMeasurements = $report['pages'];
            $metrics = collect($pageMeasurements)->keyBy('id');
            $pages = SitePage::query()->tracked()->with('latestSnapshot')->orderBy('url')->lockForUpdate()->get();
            $facts = BusinessFact::query()->with('currentVersion')->get();
            $protectedPages = PageOpportunity::query()->where('status', 'proposed')
                ->orWhere(fn ($query) => $query->where('status', 'open')->whereIn('evidence_snapshot->diagnosis_mode', ['owner', 'reviewed_claim', 'outcome_reassessment']))
                ->pluck('site_page_id')->all();
            $candidates = [];
            foreach ($pages as $page) {
                if (in_array($page->id, $protectedPages, true)) {
                    continue;
                }
                $snapshot = $page->latestSnapshot;
                if ($snapshot === null || $page->locale === null || $page->canonical_url === null) {
                    continue;
                }
                // Stale public evidence cannot justify a newly proposed edit.
                if ($snapshot->captured_at->lessThan(now()->subDays(30))) {
                    continue;
                }
                $candidate = $this->candidate($page, $metrics->get($page->id, []), $report, $facts);
                if ($candidate === null) {
                    continue;
                }
                $candidates[] = $candidate;
            }

            return DB::transaction(function () use ($project, $candidates, $pages): int {
                Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                $fingerprints = [];
                foreach ($candidates as $candidate) {
                    $fingerprints[] = $candidate['fingerprint'];
                    $existing = PageOpportunity::query()->where('fingerprint', $candidate['fingerprint'])->lockForUpdate()->first();
                    if ($existing === null) {
                        PageOpportunity::query()->create($candidate);
                    } elseif (in_array($existing->status, ['open', 'withdrawn'], true)) {
                        $existing->update([...$candidate, 'status' => 'open']);
                    }
                }
                PageOpportunity::query()->where('status', 'open')->where(fn ($query) => $query->whereNull('evidence_snapshot->diagnosis_mode')->orWhere('evidence_snapshot->diagnosis_mode', 'automatic'))->whereNotIn('fingerprint', $fingerprints)->update(['status' => 'withdrawn']);

                $count = PageOpportunity::query()->where('status', 'open')->count();
                PageOpportunityScan::query()->create([
                    'tracked_pages' => $pages->count(), 'open_count' => $count,
                    'notes' => ['Only snapshots captured within 30 days are diagnosed. Refresh older snapshots from Content.', 'English and Portuguese price/scope checks are conservative textual cues. Other locales still support evidence-led performance review.', 'Low-volume or missing Google data cannot support a confident performance diagnosis.'],
                ]);

                return $count;
            });
        }));
    }

    /** @param array<string,mixed> $outcome
     * @return array<string,mixed>|null
     */
    public function forOutcome(SitePage $page, array $outcome): ?array
    {
        $report = $this->performance->forProject($this->current->get());
        /** @var list<array<string,mixed>> $pages */
        $pages = $report['pages'];
        /** @var list<array<string,mixed>> $periods */
        $periods = $outcome['periods'];
        $metric = collect($pages)->firstWhere('id', $page->id) ?? [];
        $period = collect($periods)->first(fn (array $period): bool => $period['review_id'] !== null);
        if ($period !== null) {
            // The exact measured window supplies the trend. Current related queries
            // are contextual clues only; their dates remain separately recorded.
            $metric['current']['search'] = $period['review']['search'] ?? [];
            $metric['previous']['search'] = $period['baseline']['search'] ?? [];
            $metric['search_comparison_available'] = $period['review']['search_comparison_available'] ?? false;
            $metric['comparison_label'] = 'the pinned before/after '.$period['days'].'-day observation windows';
            $report['sources']['gsc_pages']['stale'] = ! $metric['search_comparison_available'];
        }
        $candidate = $this->candidate($page, $metric, $report, BusinessFact::query()->with('currentVersion')->get());
        if ($candidate === null) {
            return null;
        }
        $candidate['fingerprint'] = hash('sha256', $candidate['fingerprint'].'|outcome|'.$outcome['id']);
        $candidate['evidence_snapshot']['diagnosis_mode'] = 'outcome_reassessment';
        $candidate['evidence_snapshot']['outcome_review'] = $outcome;
        $candidate['evidence_snapshot']['limitations'][] = 'The saved follow-up supplies the before/after search trend. Related query rows use the separately listed current query dates. Owner feedback requests review, not an assumed causal effect.';
        $candidate['ranking_factors']['owner_feedback'] = ['points' => 1, 'reason' => $outcome['reason']];
        $candidate['ranking_factors']['priority_points']++;

        return $candidate;
    }

    /** @param array<string,mixed> $metric
     * @param  array<string,mixed>  $report
     * @param  Collection<int,BusinessFact>  $facts
     * @return array<string,mixed>|null
     */
    private function candidate(SitePage $page, array $metric, array $report, $facts): ?array
    {
        $snapshot = $page->latestSnapshot;
        if ($snapshot === null) {
            return null;
        }
        $candidate = $this->diagnose($page, $metric, $report['sources']);
        if ($candidate === null) {
            return null;
        }
        $usableFacts = $facts->filter(fn (BusinessFact $fact): bool => $fact->currentVersion?->isUsable() === true && $fact->currentVersion->source_url === $page->canonical_url)->map(fn (BusinessFact $fact): array => ['fact_id' => $fact->id, 'version_id' => $fact->currentVersion->id, 'statement' => $fact->currentVersion->statement, 'source_url' => $fact->currentVersion->source_url, 'source_note' => $fact->currentVersion->source_note])->values()->all();
        $overlap = $this->overlap->forPage($page);
        $candidate['site_page_id'] = $page->id;
        $candidate['fingerprint'] = hash('sha256', $page->id.'|'.$page->locale.'|'.$candidate['kind'].'|'.$candidate['gap']);
        unset($candidate['gap']);
        $candidate['overlap_page_ids'] = array_column($overlap, 'id');
        $candidate['evidence_snapshot'] = [
            'diagnosis_mode' => 'automatic',
            'snapshot_id' => $snapshot->id, 'source_revision' => $snapshot->revision,
            'canonical_url' => $page->canonical_url, 'locale' => $page->locale,
            'captured_at' => $snapshot->captured_at->toIso8601String(), 'windows' => $report['windows'],
            'search' => ['current' => $metric['current']['search'] ?? null, 'previous' => $metric['previous']['search'] ?? null],
            'queries' => ['current' => $metric['current']['queries'] ?? [], 'previous' => $metric['previous']['queries'] ?? []],
            'sources' => $report['sources'], 'confirmed_facts' => $usableFacts,
            'overlap_pages' => $overlap, 'observed_gap' => $candidate['diagnosed_issue'],
            'limitations' => ['Lexical checks identify a possible omission; the owner must confirm whether it matters.', 'No revenue uplift is forecast. Query data can omit private or low-volume searches.', 'Up to 500 known pages are checked for title overlap and matching locale or URL prefix. Untracked candidates need a snapshot before they can become link targets. No new URL or redirect is proposed.'],
        ];
        $candidate['diagnosed_at'] = now();

        return $candidate;
    }

    /** @param array<string, mixed> $metric
     * @param  array<string, mixed>  $sources
     * @return array<string, mixed>|null
     */
    private function diagnose(SitePage $page, array $metric, array $sources): ?array
    {
        $body = $page->latestSnapshot->fields['body_text'] ?? '';
        if (mb_strlen(trim($body)) < 80) {
            return null;
        }
        $title = $page->latestSnapshot->fields['title'] ?? $page->title;
        $subject = $title.' '.($page->latestSnapshot->fields['description'] ?? '');
        $commercial = $page->page_kind === SitePageKind::Commercial;
        $missing = $this->text->missingBuyerAnswer($body, $page->locale ?? '');
        $queryFresh = ! ($sources['gsc_queries']['stale'] ?? true);
        $pageFresh = ! ($sources['gsc_pages']['stale'] ?? true);
        $current = $metric['current']['search'] ?? [];
        $previous = $metric['previous']['search'] ?? [];
        $relevant = collect([...($metric['current']['queries'] ?? []), ...($metric['previous']['queries'] ?? [])])->filter(fn (array $query): bool => $this->text->related($query['query'], $subject));
        $kind = null;
        $gap = $missing ?? 'performance';
        $confidence = 'low';
        $reason = '';
        $scope = '';
        $searchPoints = 0;
        if ($pageFresh && $queryFresh && ($metric['search_comparison_available'] ?? false) && ($previous['clicks'] ?? 0) >= 5 && ($current['clicks'] ?? null) !== null && $previous['clicks'] - $current['clicks'] >= 3 && $current['clicks'] <= $previous['clicks'] * 0.75 && $relevant->isNotEmpty()) {
            $kind = 'declining_performance';
            $gap = 'performance';
            $reason = 'Observed clicks fell from '.$previous['clicks'].' to '.$current['clicks'].' in '.($metric['comparison_label'] ?? 'comparable 28-day windows').'. Related page queries exist; the cause is not established.';
            $scope = 'Review the existing title, description and the section serving the observed queries. Keep the page URL and working content; propose only a specific justified correction.';
            $searchPoints = 2;
            $confidence = min($current['impressions'] ?? 0, $previous['impressions'] ?? 0) >= 200 && min($current['observed_days'] ?? 0, $previous['observed_days'] ?? 0) >= 14 ? 'medium' : 'low';
        } elseif ($queryFresh && $pageFresh && $missing !== null) {
            /** @var list<array<string, mixed>> $currentQueries */
            $currentQueries = $metric['current']['queries'] ?? [];
            $query = collect($currentQueries)->first(fn (array $row): bool => ($row['impressions'] ?? 0) >= 20 && $this->text->related($row['query'], $subject) && $this->text->queryIntent($row['query']) === $missing);
            if ($query !== null) {
                $kind = 'answer_gap';
                $reason = 'The page appeared '.$query['impressions'].' times for “'.$query['query'].'”. Its captured text has no clear '.($missing === 'pricing' ? 'price or quotation' : 'included-service').' wording. Confirm this possible answer gap before editing.';
                $scope = 'Add or clarify one short answer to the observed buyer question using confirmed business facts. Reuse or link an existing relevant page where it already answers the question.';
                $searchPoints = 2;
            }
        }
        if ($kind === null && $commercial && $missing !== null) {
            $kind = 'missing_business_fact';
            $reason = 'The captured commercial page has no recognised '.($missing === 'pricing' ? 'price or quotation' : 'included-service').' wording. This is a buyer-question check, not proof that the business lacks that information.';
            $scope = 'Ask the owner for the missing detail and decide whether one concise section or an internal link would help a customer choose.';
        }
        if ($kind === null) {
            return null;
        }
        $commercialPoints = $commercial ? 3 : 1;
        $confidencePoints = $confidence === 'medium' ? 2 : 1;

        return [
            'kind' => $kind, 'gap' => $gap, 'diagnosed_issue' => $reason, 'suggested_scope' => $scope,
            'confidence' => $confidence, 'effort' => 'small',
            'missing_fact_questions' => $missing !== null ? [$this->text->question($missing, $title)] : [],
            'ranking_factors' => [
                'commercial_relevance' => ['points' => $commercialPoints, 'reason' => $commercial ? 'The owner classified this as a commercial page.' : 'A supporting article with relevant query evidence.'],
                'search_evidence' => ['points' => $searchPoints, 'reason' => $searchPoints > 0 ? 'Settled, related query observations support review.' : 'No strong search diagnosis; review buyer information with low confidence.'],
                'content_gap' => ['points' => $missing !== null ? 2 : 1, 'reason' => $missing !== null ? 'A possible buyer-answer omission needs confirmation.' : 'A specific content issue still needs review; the decline alone does not justify rewriting.'],
                'confidence' => ['points' => $confidencePoints, 'reason' => $confidence === 'medium' ? 'Both windows have at least 200 observed impressions; this still does not establish cause.' : 'Low volume or a lexical signal limits confidence.'],
                'effort' => ['points' => 1, 'reason' => 'A bounded title, description, text section or internal link; operator review is still required.'],
                'priority_points' => $commercialPoints + $searchPoints + ($missing !== null ? 2 : 1) + $confidencePoints + 1,
            ],
        ];
    }
}
