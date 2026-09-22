<?php

declare(strict_types=1);

namespace App\Visibility\Accuracy;

use App\Models\AiAccuracyAssessment;
use App\Models\AiAccuracyFinding;
use App\Models\AiCorrectionAction;
use App\Models\AiCorrectionRecheck;
use App\Models\AiCorrectionUpdate;
use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Opportunities\RecordClaimOpportunity;
use App\Visibility\Sampling\SamplingRuns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CorrectionActions
{
    public function __construct(private readonly AccuracyAssessments $assessments) {}

    public function ownedPage(User $actor, AiAccuracyFinding $pageFinding): AiCorrectionAction
    {
        $project = $this->assessments->owner($actor);

        return DB::transaction(function () use ($project, $actor, $pageFinding): AiCorrectionAction {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $fact = $this->assessments->confirmed($pageFinding);
            $pageAssessment = AiAccuracyAssessment::query()->findOrFail($pageFinding->assessment_id);
            abort_unless($pageAssessment->scope === 'owned_page' && $pageAssessment->parent_finding_id !== null, 409);
            $original = AiAccuracyFinding::query()->findOrFail($pageAssessment->parent_finding_id);
            abort_unless($this->assessments->confirmed($original)->id === $fact->id, 409, 'The page and answer findings must compare the same confirmed fact.');
            $existing = AiCorrectionAction::query()->where('owned_page_finding_id', $pageFinding->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $page = SitePage::query()->findOrFail($pageAssessment->site_page_id);
            $snapshot = PageSnapshot::query()->findOrFail($pageAssessment->snapshot_id);
            abort_unless(PageCorrectionSupport::current($page, $pageAssessment), 409, 'Capture and review the current public page before preparing this correction.');
            if (! PageCorrectionSupport::editable($snapshot, $pageFinding)) {
                $draft = implode("\n\n", ['Assisted owned-page correction for manual review', 'Page: '.$snapshot->source_url,
                    'Captured: '.$snapshot->captured_at->toIso8601String().' | Snapshot '.$snapshot->id,
                    'Surface: '.$pageFinding->evidence['sectionKey'], 'Exact reviewed statement: '.$pageFinding->evidence['exactQuote'],
                    'Current confirmed fact: '.$fact->statement, 'Fact version: '.$fact->id,
                    'Fact source: '.($fact->source_url ?? $fact->source_note), 'Comparison: '.$pageFinding->evidence['reason'],
                    'This quote is outside the bounded editor or exceeds its supported scope. Have the site owner review and change the exact relevant page/template/structured-data surface in their CMS, preserving unrelated content. No edit has been drafted, approved or sent automatically. Capture the public page again and review it after any manual change.']);

                return AiCorrectionAction::query()->create(['finding_id' => $original->id, 'owned_page_finding_id' => $pageFinding->id,
                    'kind' => 'owned_page_assisted', 'draft' => $draft, 'created_by' => $actor->id]);
            }
            $opportunity = app(RecordClaimOpportunity::class)->record($actor, $page, $snapshot, $fact, 'ai_answer_finding', $original->id,
                ['owned_page_quote' => $pageFinding->evidence['exactQuote'], 'issue' => 'A reviewed public-page statement conflicts with a confirmed business fact.',
                    'reason' => $pageFinding->evidence['reason'], 'answer_id' => $pageAssessment->answer_id, 'answer_finding_id' => $original->id,
                    'owned_page_finding_id' => $pageFinding->id, 'answer_quote' => $original->evidence['exactQuote'], 'answer_assessment_id' => $original->assessment_id,
                    'page_assessment_id' => $pageAssessment->id, 'fact_version_id' => $fact->id, 'page_evidence' => $pageFinding->evidence]);

            return AiCorrectionAction::query()->create(['finding_id' => $original->id, 'owned_page_finding_id' => $pageFinding->id,
                'opportunity_id' => $opportunity->id, 'kind' => 'owned_page', 'draft' => 'Prepare and review the bounded correction in the linked opportunity. Publication requires its own explicit action.', 'created_by' => $actor->id]);
        });
    }

    public function handoff(User $actor, AiAccuracyFinding $finding): AiCorrectionAction
    {
        $project = $this->assessments->owner($actor);

        return DB::transaction(function () use ($project, $actor, $finding): AiCorrectionAction {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $fact = $this->assessments->confirmed($finding);
            $assessment = AiAccuracyAssessment::query()->findOrFail($finding->assessment_id);
            abort_unless($assessment->scope === 'answer', 409);
            $existing = AiCorrectionAction::query()->where('finding_id', $finding->id)->where('kind', 'external_handoff')->first();
            if ($existing !== null) {
                return $existing;
            }
            $answer = AiSamplingAnswer::query()->findOrFail($assessment->answer_id);
            $cell = AiSamplingCell::query()->findOrFail($answer->sampling_cell_id);
            $sources = implode("\n", array_map(fn (array $citation): string => '- '.$citation['url'], $answer->citations));
            $draft = implode("\n\n", [
                'Request to review a factual business statement',
                'I represent '.$project->name.'. A sampled '.$cell->specification['platform']['platform'].' API answer recorded on '.$answer->received_at->toIso8601String().' said:',
                '“'.$finding->evidence['exactQuote'].'”',
                'Question asked: '.$cell->specification['prompt']['text'],
                'Our confirmed current information: '.$fact->statement,
                'Source: '.($fact->source_url ?? 'Owner-confirmed business information; no public source URL supplied.'),
                'Please review this statement and the supporting information through your applicable correction process.',
                'Cited sources in the sampled answer (these do not establish where the error originated):'.($sources === '' ? ' None returned.' : "\n".$sources),
                'This is a sampled API answer. It may differ from answers shown to individual customers.',
            ]);

            return AiCorrectionAction::query()->create(['finding_id' => $finding->id, 'kind' => 'external_handoff', 'draft' => $draft, 'created_by' => $actor->id]);
        });
    }

    /** @param array<string, mixed> $input */
    public function recordHandoff(User $actor, AiCorrectionAction $action, array $input): AiCorrectionUpdate
    {
        $project = $this->assessments->owner($actor);
        $data = Validator::make($input, ['status' => ['required', Rule::in(['owner_submitted', 'owner_cancelled'])], 'note' => ['required', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($project, $actor, $action, $data): AiCorrectionUpdate {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless($action->project_id === $project->id && in_array($action->kind, ['external_handoff', 'owned_page_assisted'], true), 409);
            if ($data['status'] === 'owner_submitted') {
                $this->assessments->confirmed(AiAccuracyFinding::query()->findOrFail($action->finding_id));
                if ($action->owned_page_finding_id !== null) {
                    $this->assessments->confirmed(AiAccuracyFinding::query()->findOrFail($action->owned_page_finding_id));
                }
            }

            return AiCorrectionUpdate::query()->create(['action_id' => $action->id, 'recorded_by' => $actor->id, ...$data]);
        });
    }

    public function recheck(User $actor, AiCorrectionAction $action, string $requestKey): AiSamplingRun
    {
        $project = $this->assessments->owner($actor);
        abort_unless($action->project_id === $project->id, 404);
        Validator::make(['request_key' => $requestKey], ['request_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $action, $requestKey, $project): AiSamplingRun {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $finding = AiAccuracyFinding::query()->findOrFail($action->finding_id);
            $assessment = AiAccuracyAssessment::query()->findOrFail($finding->assessment_id);
            $answer = AiSamplingAnswer::query()->findOrFail($assessment->answer_id);
            $cell = AiSamplingCell::query()->findOrFail($answer->sampling_cell_id);
            $previous = AiSamplingRun::query()->findOrFail($cell->sampling_run_id);
            $set = AiSamplingSet::query()->findOrFail($previous->sampling_set_id);
            $run = app(SamplingRuns::class)->start($project, $set, $requestKey, $actor, $previous, [$cell->cell_key]);
            AiCorrectionRecheck::query()->firstOrCreate(['action_id' => $action->id, 'sampling_run_id' => $run->id]);

            return $run;
        });
    }
}
