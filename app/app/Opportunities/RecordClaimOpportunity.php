<?php

declare(strict_types=1);

namespace App\Opportunities;

use App\Feedback\Measurements\PagePerformance;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\PageOpportunity;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** The calling workflow must verify its saved finding and explicit owner review. */
final class RecordClaimOpportunity
{
    public function __construct(private readonly CurrentProject $current, private readonly PagePerformance $performance, private readonly ExistingPageOverlap $overlap) {}

    /**
     * Evidence must contain owned_page_quote, issue and reason; extra source IDs
     * and exact discrepancy evidence are retained without becoming business facts.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function record(User $actor, SitePage $page, PageSnapshot $snapshot, BusinessFactVersion $fact, string $originType, string $originId, array $evidence): PageOpportunity
    {
        $project = $this->current->get() ?? abort(404);
        abort_unless($actor->projects()->whereKey($project->id)->wherePivot('role', 'owner')->exists(), 403);
        abort_unless($page->project_id === $project->id && $snapshot->project_id === $project->id && $fact->project_id === $project->id, 404);
        Validator::make([...$evidence, 'origin_type' => $originType, 'origin_id' => $originId], [
            'origin_type' => ['required', Rule::in(['ai_answer_finding', 'fact_maintenance'])],
            'origin_id' => ['required', 'ulid'], 'owned_page_quote' => ['required', 'string', 'max:2400'],
            'issue' => ['required', 'string', 'max:1000'], 'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($project, $actor, $page, $snapshot, $fact, $originType, $originId, $evidence): PageOpportunity {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $page = SitePage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $latest = $page->latestSnapshot;
            $currentFact = BusinessFact::query()->whereKey($fact->business_fact_id)->lockForUpdate()->firstOrFail();
            $fact = BusinessFactVersion::query()->findOrFail($fact->id);
            abort_unless($currentFact->current_version_id === $fact->id && $fact->isUsable(), 409, 'The confirmed fact changed or needs review. Recheck the finding against its current version.');
            abort_unless($page->tracked_at !== null && $latest?->id === $snapshot->id && $snapshot->site_page_id === $page->id
                && $snapshot->source_kind === 'public' && $snapshot->captured_at->greaterThanOrEqualTo(now()->subDays(30))
                && ($snapshot->metadata['canonical_url'] ?? null) === $page->canonical_url
                && ($snapshot->metadata['locale'] ?? null) === $page->locale, 409, 'The selected page changed. Capture and review its current text first.');
            $texts = array_filter([$snapshot->fields['title'] ?? null, $snapshot->fields['description'] ?? null, $snapshot->fields['body_text'] ?? null], 'is_string');
            foreach ($snapshot->metadata['fact_surfaces']['surfaces'] ?? [] as $surface) {
                if (is_string($surface['text'] ?? null)) {
                    $texts[] = $surface['text'];
                }
            }
            abort_unless(collect($texts)->contains(fn (string $text): bool => str_contains($text, $evidence['owned_page_quote'])), 409, 'The reviewed quote is not present in this saved page.');
            $fingerprint = hash('sha256', implode('|', ['claim', $originType, $originId, $page->id, $snapshot->id, $fact->id]));
            $existing = PageOpportunity::query()->where('fingerprint', $fingerprint)->first();
            if ($existing !== null) {
                return $existing;
            }
            $report = $this->performance->forProject($project);
            /** @var list<array<string, mixed>> $metrics */
            $metrics = $report['pages'];
            $metric = collect($metrics)->firstWhere('id', $page->id);
            $overlap = $this->overlap->forPage($page);

            return PageOpportunity::query()->create([
                'site_page_id' => $page->id, 'kind' => 'missing_business_fact',
                'diagnosed_issue' => $evidence['issue'],
                'suggested_scope' => 'Correct the reviewed statement using the current confirmed fact. Preserve unrelated text and page structure; use an assisted handoff for unsupported fields.',
                'confidence' => 'high', 'effort' => 'small', 'status' => 'open',
                'ranking_factors' => [
                    'commercial_relevance' => ['points' => 2, 'reason' => 'An owner-reviewed business fact is represented inaccurately on an existing tracked page.'],
                    'search_evidence' => ['points' => 0, 'reason' => 'This is a reviewed factual discrepancy, with no predicted traffic or revenue gain.'],
                    'content_gap' => ['points' => 3, 'reason' => $evidence['reason']],
                    'confidence' => ['points' => 3, 'reason' => 'The owner reviewed the exact page quote against a current confirmed fact.'],
                    'effort' => ['points' => 1, 'reason' => 'Prepare a bounded correction and review its actual editing support.'],
                    'priority_points' => 9,
                ],
                'missing_fact_questions' => [], 'overlap_page_ids' => array_column($overlap, 'id'),
                'fingerprint' => $fingerprint, 'diagnosed_at' => now(),
                'evidence_snapshot' => [
                    'diagnosis_mode' => 'reviewed_claim', 'origin_type' => $originType, 'origin_id' => $originId,
                    'recorded_by' => $actor->id, 'reviewed_claim' => $evidence,
                    'snapshot_id' => $snapshot->id, 'source_revision' => $snapshot->revision,
                    'canonical_url' => $page->canonical_url, 'locale' => $page->locale,
                    'captured_at' => $snapshot->captured_at->toIso8601String(), 'quoted_excerpt' => $evidence['owned_page_quote'],
                    'windows' => $report['windows'], 'sources' => $report['sources'],
                    'search' => ['current' => $metric['current']['search'] ?? null, 'previous' => $metric['previous']['search'] ?? null],
                    'queries' => ['current' => $metric['current']['queries'] ?? [], 'previous' => $metric['previous']['queries'] ?? []],
                    'confirmed_facts' => [['version_id' => $fact->id, 'fact_id' => $fact->business_fact_id, 'statement' => $fact->statement, 'source_url' => $fact->source_url, 'source_note' => $fact->source_note, 'confirmed_at' => $fact->confirmed_at?->toIso8601String()]],
                    'overlap_pages' => $overlap,
                    'limitations' => ['An exact quote establishes what the page says. The separately confirmed current fact supplies the proposed correction.', 'Creating a correction opportunity does not approve or publish an edit.'],
                ],
            ]);
        });
    }
}
