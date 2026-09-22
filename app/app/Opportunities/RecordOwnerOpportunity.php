<?php

declare(strict_types=1);

namespace App\Opportunities;

use App\Enums\SitePageKind;
use App\Feedback\Measurements\PagePerformance;
use App\Models\PageOpportunity;
use App\Models\Project;
use App\Models\SitePage;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owner diagnosis is explicitly attributed and pinned to observed text, not confirmed truth. */
final class RecordOwnerOpportunity
{
    public function __construct(private readonly CurrentProject $current, private readonly PagePerformance $performance, private readonly ExistingPageOverlap $overlap) {}

    /** @param array{snapshot_id: string, quoted_excerpt: string, additional_excerpt?: string|null, question: string} $data */
    public function record(Project $project, SitePage $page, array $data, int $actorId, bool $reactivate = false): PageOpportunity
    {
        return $this->current->run($project, fn () => DB::transaction(function () use ($project, $page, $data, $actorId, $reactivate): PageOpportunity {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $page = SitePage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $snapshot = $page->latestSnapshot;
            if ($page->project_id !== $project->id || $page->tracked_at === null || $page->page_kind !== SitePageKind::Commercial || $snapshot === null || $snapshot->id !== $data['snapshot_id'] || $snapshot->captured_at->lessThan(now()->subDays(30))) {
                throw ValidationException::withMessages(['site_page_id' => 'Choose a tracked commercial page with a current snapshot. Refresh the page if its snapshot has changed.']);
            }
            foreach (['quoted_excerpt', 'additional_excerpt'] as $field) {
                $excerpt = trim($data[$field] ?? '');
                if ($excerpt !== '' && ! str_contains($snapshot->fields['body_text'] ?? '', $excerpt)) {
                    throw ValidationException::withMessages([$field => 'Copy an exact excerpt from this page’s saved text. It is evidence of what is written, not confirmation that the claim is correct.']);
                }
            }
            $report = $this->performance->forProject($project);
            /** @var list<array<string, mixed>> $pageMetrics */
            $pageMetrics = $report['pages'];
            $metric = collect($pageMetrics)->firstWhere('id', $page->id);
            $fingerprint = hash('sha256', 'owner|'.$page->id.'|'.trim($data['question']));
            $overlap = $this->overlap->forPage($page);

            return DB::transaction(function () use ($project, $page, $snapshot, $report, $metric, $fingerprint, $data, $actorId, $overlap, $reactivate): PageOpportunity {
                Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                $existing = PageOpportunity::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
                if ($existing !== null && ! $reactivate) {
                    return $existing;
                }
                if ($reactivate) {
                    abort_unless($existing !== null && $existing->status === 'dismissed' && ($existing->evidence_snapshot['diagnosis_mode'] ?? '') === 'owner', 409, 'This question has changed. Review its current state.');
                }

                $values = [
                    'site_page_id' => $page->id, 'kind' => 'missing_business_fact',
                    'diagnosed_issue' => 'Flagged for owner review: '.trim($data['question']),
                    'suggested_scope' => 'Confirm the actual business rule, then clarify only the quoted section or link to an existing answer. The quoted text alone is not a confirmed fact.',
                    'confidence' => 'low', 'effort' => 'small',
                    'ranking_factors' => [
                        'commercial_relevance' => ['points' => 3, 'reason' => 'The owner identified a question on a tracked commercial page.'],
                        'search_evidence' => ['points' => 0, 'reason' => 'This is an owner-reported information problem, not a traffic forecast.'],
                        'content_gap' => ['points' => 3, 'reason' => 'Exact page excerpts support inspection of the question; its correct answer still needs confirmation.'],
                        'confidence' => ['points' => 1, 'reason' => 'The impact and correct business rule are not yet established.'],
                        'effort' => ['points' => 1, 'reason' => 'Clarify a bounded part of the existing page.'],
                        'priority_points' => 8,
                    ],
                    'missing_fact_questions' => [trim($data['question'])], 'overlap_page_ids' => array_column($overlap, 'id'),
                    'fingerprint' => $fingerprint, 'diagnosed_at' => now(),
                    'evidence_snapshot' => [
                        'diagnosis_mode' => 'owner', 'recorded_by' => $actorId,
                        'snapshot_id' => $snapshot->id, 'source_revision' => $snapshot->revision,
                        'canonical_url' => $page->canonical_url, 'locale' => $page->locale,
                        'captured_at' => $snapshot->captured_at->toIso8601String(),
                        'quoted_excerpt' => $data['quoted_excerpt'], 'additional_excerpt' => $data['additional_excerpt'] ?? null,
                        'windows' => $report['windows'], 'sources' => $report['sources'],
                        'search' => ['current' => $metric['current']['search'] ?? null, 'previous' => $metric['previous']['search'] ?? null],
                        'queries' => ['current' => $metric['current']['queries'] ?? [], 'previous' => $metric['previous']['queries'] ?? []],
                        'confirmed_facts' => [], 'overlap_pages' => $overlap,
                        'limitations' => ['This diagnosis was entered by the owner. Excerpts prove what the snapshot says, not which business rule is correct.', 'Check other existing pages before adding content. A factual answer requires a separate sourced confirmation.'],
                    ],
                ];
                if ($existing !== null) {
                    $existing->update([...$values, 'status' => 'open']);

                    return $existing;
                }

                return PageOpportunity::query()->create($values);
            });
        }));
    }
}
