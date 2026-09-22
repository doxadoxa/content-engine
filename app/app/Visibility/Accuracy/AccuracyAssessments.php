<?php

declare(strict_types=1);

namespace App\Visibility\Accuracy;

use App\Billing\Entitlements;
use App\Facts\FactSection;
use App\Models\AiAccuracyAssessment;
use App\Models\AiAccuracyFinding;
use App\Models\AiAccuracyReview;
use App\Models\AiCorrectionRecheck;
use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Pages\TrackedPages;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

final class AccuracyAssessments
{
    public function __construct(private readonly CurrentProject $current) {}

    public function answer(User $actor, AiSamplingAnswer $answer, string $requestKey): AiAccuracyAssessment
    {
        $project = $this->owner($actor);
        abort_unless($answer->project_id === $project->id, 404);
        $cell = AiSamplingCell::query()->findOrFail($answer->sampling_cell_id);
        $sections = [];
        foreach ($answer->sections as $index => $section) {
            $references = [];
            foreach ($section['annotations'] ?? [] as $referenceIndex => $citation) {
                $references[] = ['id' => 'citation:'.$index.':'.$referenceIndex, 'url' => $citation['url'], 'title' => $citation['title']];
            }
            $sections[] = new FactSection('answer:'.$index, $section['text'], ['business' => $cell->specification['brand'], 'question' => $cell->specification['prompt']['text'],
                'locale' => $cell->specification['prompt']['locale'], 'source' => 'recorded_provider_final_answer'], $references);
        }
        $facts = BusinessFact::query()->with('currentVersion')->get()->map(fn (BusinessFact $fact) => $fact->currentVersion)->filter(fn (?BusinessFactVersion $version): bool => $version?->isUsable() === true)->values()->all();

        return $this->record($actor, $answer, $requestKey, $sections, array_values($facts), ['answer_metadata' => $answer->metadata,
            'captured_at' => $answer->received_at->toIso8601String(), 'limitations' => ['The checker assesses the stored final text. It cannot establish whether the provider omitted or truncated content before returning it.']], 'answer');
    }

    public function page(User $actor, AiAccuracyFinding $finding, SitePage $page, string $requestKey): AiAccuracyAssessment
    {
        $project = $this->owner($actor);
        $fact = $this->confirmed($finding);
        $source = AiAccuracyAssessment::query()->findOrFail($finding->assessment_id);
        abort_unless($source->scope === 'answer' && $page->project_id === $project->id && $page->tracked_at !== null, 409);
        $existing = AiAccuracyAssessment::query()->where('request_key', $requestKey)->first();
        if ($existing !== null) {
            abort_unless($existing->parent_finding_id === $finding->id && $existing->site_page_id === $page->id, 409);

            return $existing;
        }
        $this->requirePaidWork($project);
        $captured = app(TrackedPages::class)->capture($project, $page);
        $snapshot = $captured->latestSnapshot ?? abort(409, 'No public source was captured.');
        $surfaces = $snapshot->metadata['fact_surfaces'] ?? [];
        $sections = [];
        foreach ($surfaces['surfaces'] ?? [] as $surface) {
            $sections[] = new FactSection($surface['id'], $surface['text'], ['business' => $project->name, 'source' => 'delivered_public_page',
                'url' => $snapshot->source_url, 'kind' => $surface['kind'], 'locator' => $surface['locator'], 'locale' => $page->locale],
                [['id' => 'owned-page', 'url' => $snapshot->source_url, 'title' => $page->title]]);
        }

        return $this->record($actor, AiSamplingAnswer::query()->findOrFail($source->answer_id), $requestKey, $sections, [$fact],
            ['capture_status' => $surfaces['status'] ?? 'unavailable', 'limitations' => $surfaces['limitations'] ?? ['The saved capture has no factual surfaces.'],
                'omitted' => $surfaces['omitted'] ?? [], 'source_url' => $snapshot->source_url, 'captured_at' => $snapshot->captured_at->toIso8601String()],
            'owned_page', $page->id, $snapshot->id, $finding->id);
    }

    /** @param array<string, mixed> $input */
    public function review(User $actor, AiAccuracyFinding $finding, array $input): AiAccuracyReview
    {
        $project = $this->owner($actor);
        $data = Validator::make($input, ['decision' => ['required', Rule::in(['confirmed', 'dismissed'])], 'reason' => ['required', 'string', 'max:2000'],
            'expected_review_id' => ['nullable', 'ulid'], 'reopen' => ['sometimes', 'boolean']])->validate();

        return DB::transaction(function () use ($project, $actor, $finding, $data): AiAccuracyReview {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $finding = AiAccuracyFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();
            $latest = AiAccuracyReview::query()->where('finding_id', $finding->id)->latest('id')->first();
            abort_unless(($data['expected_review_id'] ?? null) === $latest?->id, 409, 'Another owner review was saved. Reload and review the latest decision before changing it.');
            abort_if($latest?->decision === 'dismissed' && $data['decision'] === 'confirmed' && ! ($data['reopen'] ?? false), 409, 'Explicitly reopen this dismissed finding before confirming it.');
            if ($data['decision'] === 'confirmed' && $finding->fact_version_id !== null) {
                $this->currentFact($finding->fact_version_id);
            }

            return AiAccuracyReview::query()->create(['finding_id' => $finding->id, 'reviewed_by' => $actor->id, 'decision' => $data['decision'], 'reason' => $data['reason']]);
        });
    }

    public function confirmed(AiAccuracyFinding $finding): BusinessFactVersion
    {
        abort_unless($finding->project_id === $this->current->id(), 404);
        $review = AiAccuracyReview::query()->where('finding_id', $finding->id)->latest('id')->first();
        abort_unless($finding->relation === 'contradicted' && $finding->fact_version_id !== null && $review?->decision === 'confirmed', 409, 'Confirm this exact discrepancy against a current fact before preparing a correction.');

        return $this->currentFact($finding->fact_version_id);
    }

    public function currentFact(string $versionId): BusinessFactVersion
    {
        $version = BusinessFactVersion::query()->findOrFail($versionId);
        $fact = BusinessFact::query()->findOrFail($version->business_fact_id);
        abort_unless($fact->current_version_id === $version->id && $version->isUsable(), 409, 'The business fact changed or needs review. Run a fresh assessment first.');

        return $version;
    }

    public function owner(User $actor): Project
    {
        $project = $this->current->get() ?? abort(404);
        abort_unless($actor->projects()->whereKey($project->id)->wherePivot('role', 'owner')->exists(), 403);

        return $project;
    }

    /** Only an explicit correction recheck authorizes automatic assessment of its new answer. */
    public function recheckRun(string $runId): void
    {
        if (! AiCorrectionRecheck::query()->where('sampling_run_id', $runId)->exists()) {
            return;
        }
        $run = AiSamplingRun::query()->findOrFail($runId);
        $actor = $run->requested_by === null ? null : User::query()->find($run->requested_by);
        if ($actor === null) {
            return;
        }
        $project = $this->owner($actor);
        $cells = AiSamplingCell::query()->where('sampling_run_id', $runId)->where('status', 'answered')->pluck('id');
        foreach (AiSamplingAnswer::query()->whereIn('sampling_cell_id', $cells)->get() as $answer) {
            $assessment = $this->answer($actor, $answer, Uuid::uuid5(Uuid::NAMESPACE_URL, 'avyo:correction-recheck:'.$answer->id)->toString());
            $this->dispatch($project, $assessment);
        }
    }

    public function dispatch(Project $project, AiAccuracyAssessment $assessment): void
    {
        $this->current->run($project, fn () => DB::transaction(function () use ($project, $assessment): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $assessment->refresh();
            if ($assessment->status !== 'queued' || $assessment->pipeline_run_id !== null) {
                return;
            }
            $run = app(PipelineRunner::class)->start('ai_accuracy', $project, ['assessment_id' => $assessment->id]);
            // A synchronous test worker may already have completed the assessment.
            AiAccuracyAssessment::query()->whereKey($assessment->id)->whereNull('pipeline_run_id')->update(['pipeline_run_id' => $run->id]);
        }));
    }

    /**
     * @param  list<FactSection>  $sections
     * @param  list<BusinessFactVersion>  $facts
     * @param  array<string, mixed>  $metadata
     */
    private function record(User $actor, AiSamplingAnswer $answer, string $requestKey, array $sections, array $facts, array $metadata, string $scope, ?string $pageId = null, ?string $snapshotId = null, ?string $parentId = null): AiAccuracyAssessment
    {
        $project = $this->owner($actor);
        Validator::make(['request_key' => $requestKey], ['request_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($project, $actor, $answer, $requestKey, $sections, $facts, $metadata, $scope, $pageId, $snapshotId, $parentId): AiAccuracyAssessment {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $existing = AiAccuracyAssessment::query()->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                abort_unless($existing->answer_id === $answer->id && $existing->scope === $scope && $existing->site_page_id === $pageId, 409, 'This submission identity belongs to another assessment.');

                return $existing;
            }
            $this->requirePaidWork($project);
            if ($parentId !== null) {
                $this->confirmed(AiAccuracyFinding::query()->findOrFail($parentId));
            }
            $pinned = array_map(function (BusinessFactVersion $fact): array {
                $version = $this->currentFact($fact->id);

                return ['id' => $version->id, 'business_fact_id' => $version->business_fact_id, 'statement' => $version->statement, 'source_url' => $version->source_url,
                    'source_note' => $version->source_note, 'confirmed_at' => $version->confirmed_at?->toIso8601String(), 'review_due_at' => $version->review_due_at?->toIso8601String()];
            }, $facts);
            $input = array_map(fn (FactSection $section): array => get_object_vars($section), $sections);
            $assessment = AiAccuracyAssessment::query()->create(['answer_id' => $answer->id, 'scope' => $scope, 'site_page_id' => $pageId, 'snapshot_id' => $snapshotId, 'parent_finding_id' => $parentId,
                'request_key' => $requestKey, 'sections' => $input, 'fact_versions' => $pinned, 'source_metadata' => $metadata,
                'source_hash' => hash('sha256', json_encode([$input, $pinned, $metadata], JSON_THROW_ON_ERROR)), 'requested_by' => $actor->id, 'status' => 'queued']);
            DB::afterCommit(fn () => $this->dispatch($project, $assessment));

            return $assessment;
        });
    }

    private function requirePaidWork(Project $project): void
    {
        $entitlements = app(Entitlements::class);
        $entitlements->forget($project);
        $refusal = $entitlements->for($project)->refusal();
        abort_if($refusal !== null, 409, $refusal->message ?? 'Paid work is unavailable.');
    }
}
