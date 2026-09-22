<?php

declare(strict_types=1);

namespace App\Visibility\Accuracy;

use App\Models\AiAccuracyAssessment;
use App\Models\AiAccuracyFinding;
use App\Models\AiAccuracyReview;
use App\Models\AiCorrectionAction;
use App\Models\AiCorrectionRecheck;
use App\Models\AiCorrectionUpdate;
use App\Models\AiSamplingAnswer;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\SitePage;

final class AccuracyReport
{
    /** @return array<string, mixed> */
    public function answer(AiSamplingAnswer $answer): array
    {
        $current = BusinessFact::query()->with('currentVersion')->get()->map(fn (BusinessFact $fact) => $fact->currentVersion)->filter(fn (?BusinessFactVersion $version): bool => $version?->isUsable() === true)->keyBy('id');
        $assessments = AiAccuracyAssessment::query()->where('answer_id', $answer->id)->orderByDesc('id')->limit(30)->get();

        return ['current_fact_count' => $current->count(), 'assessments' => $assessments->map(function (AiAccuracyAssessment $assessment) use ($current): array {
            $factIds = array_column($assessment->fact_versions, 'id');
            $factsCurrent = $factIds !== [] && array_diff($factIds, $current->keys()->all()) === [];
            $page = $assessment->site_page_id === null ? null : SitePage::query()->find($assessment->site_page_id);
            $sourceCurrent = $assessment->scope === 'answer' || PageCorrectionSupport::current($page, $assessment);
            $status = $assessment->status === 'running' && $assessment->started_at?->lessThan(now()->subMinutes(31)) ? 'indeterminate' : $assessment->status;

            return ['id' => $assessment->id, 'scope' => $assessment->scope, 'status' => $status, 'created_at' => $assessment->created_at->toIso8601String(),
                'facts_current' => $factsCurrent, 'source_current' => $sourceCurrent, 'fact_versions' => $assessment->fact_versions,
                'source_metadata' => $assessment->source_metadata, 'result' => $assessment->result, 'parent_finding_id' => $assessment->parent_finding_id,
                'findings' => AiAccuracyFinding::query()->where('assessment_id', $assessment->id)->get()->map(function (AiAccuracyFinding $finding) use ($factsCurrent, $sourceCurrent, $assessment, $page): array {
                    $history = AiAccuracyReview::query()->where('finding_id', $finding->id)->latest('id')->get();
                    $review = $history->first();

                    return ['id' => $finding->id, 'relation' => $finding->relation, 'fact_version_id' => $finding->fact_version_id, 'evidence' => $finding->evidence,
                        'references' => collect($assessment->sections)->where('key', $finding->evidence['sectionKey'])->flatMap(fn (array $section): array => $section['references'])->filter(fn (array $reference): bool => in_array($reference['id'], $finding->evidence['referenceIds'], true))->values()->all(),
                        'review' => $review, 'review_history' => $history, 'can_edit_page' => $page?->latestSnapshot !== null && PageCorrectionSupport::editable($page->latestSnapshot, $finding),
                        'can_correct' => $factsCurrent && $sourceCurrent && $review?->decision === 'confirmed' && $finding->relation === 'contradicted' && $finding->fact_version_id !== null,
                        'actions' => AiCorrectionAction::query()->where('finding_id', $finding->id)->get()->map(fn (AiCorrectionAction $action): array => [
                            ...$action->toArray(), 'updates' => AiCorrectionUpdate::query()->where('action_id', $action->id)->orderByDesc('id')->get(),
                            'rechecks' => AiCorrectionRecheck::query()->where('action_id', $action->id)->orderByDesc('id')->get(),
                        ])->all()];
                })->all()];
        })->all(), 'tracked_pages' => SitePage::query()->tracked()->orderBy('title')->get(['id', 'title', 'canonical_url'])];
    }
}
