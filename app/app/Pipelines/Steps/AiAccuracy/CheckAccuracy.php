<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\AiAccuracy;

use App\Billing\Entitlements;
use App\Facts\AssessFactClaims;
use App\Facts\FactSection;
use App\Models\AiAccuracyAssessment;
use App\Models\AiAccuracyFinding;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\Project;
use App\Models\User;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Illuminate\Support\Facades\DB;

final class CheckAccuracy extends AbstractStep
{
    public function __construct(private readonly AssessFactClaims $checker) {}

    public static function key(): string
    {
        return 'assess_recorded_factual_claims';
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function timeout(): int
    {
        return 1800;
    }

    public function handle(StepContext $context): StepResult
    {
        $assessment = AiAccuracyAssessment::query()->findOrFail((string) $context->get('assessment_id'));
        $claimed = DB::transaction(function () use ($assessment, $context): bool {
            $locked = AiAccuracyAssessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'queued') {
                return false;
            }
            $entitlements = app(Entitlements::class);
            $entitlements->forget($context->project);
            $actor = $locked->requested_by === null ? null : User::query()->find($locked->requested_by);
            if (! $entitlements->for($context->project)->mayGenerate() || ! ($actor?->projects()->whereKey($context->project->id)->wherePivot('role', 'owner')->exists() ?? false)) {
                $locked->update(['status' => 'unavailable', 'finished_at' => now(), 'result' => ['status' => 'unavailable', 'findings' => [], 'coverage' => ['attempted_calls' => 0], 'checker' => ['calls' => []], 'limitations' => ['The project or requesting owner is no longer eligible for paid work. No checker request was made.']]]);

                return false;
            }
            $locked->update(['status' => 'running', 'started_at' => now(), 'pipeline_run_id' => $context->run->id]);

            return true;
        });
        if (! $claimed) {
            return StepResult::skip('This assessment was already claimed. Unknown outcomes require an explicit new assessment.');
        }
        $facts = BusinessFactVersion::query()->whereIn('id', array_column($assessment->fact_versions, 'id'))->get()->values()->all();
        $sections = array_map(fn (array $section): FactSection => new FactSection($section['key'], $section['text'], $section['context'], $section['references']), $assessment->sections);
        $result = $this->checker->assess($context, $sections, array_values($facts))->toArray();
        if (($assessment->source_metadata['capture_status'] ?? null) !== null && $assessment->source_metadata['capture_status'] !== 'captured' && $result['status'] === 'complete') {
            $result['status'] = 'partial';
        }
        $result['limitations'] = array_values(array_unique([...$result['limitations'], ...($assessment->source_metadata['limitations'] ?? [])]));
        DB::transaction(function () use ($assessment, $result, $context): void {
            Project::query()->whereKey($context->project->id)->lockForUpdate()->firstOrFail();
            $currentIds = BusinessFact::query()->whereIn('current_version_id', array_column($assessment->fact_versions, 'id'))->pluck('current_version_id')->all();
            if (array_diff(array_column($assessment->fact_versions, 'id'), $currentIds) !== []) {
                $result['checker']['invalidated_findings'] = $result['findings'];
                $result['findings'] = [];
                $result['status'] = 'unavailable';
                $result['limitations'][] = 'A fact changed before this assessment was saved. Request a fresh assessment.';
            }
            $locked = AiAccuracyAssessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'running') {
                return;
            }
            foreach ($result['findings'] as $finding) {
                AiAccuracyFinding::query()->create(['assessment_id' => $assessment->id, 'relation' => $finding['relation'], 'fact_version_id' => $finding['factVersionId'], 'evidence' => $finding]);
            }
            $locked->update(['status' => $result['status'], 'result' => $result, 'finished_at' => now()]);
        });

        return StepResult::success();
    }
}
