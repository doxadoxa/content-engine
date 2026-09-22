<?php

declare(strict_types=1);

namespace App\Support\Metering;

use App\Ai\ModelCatalog;
use App\Models\AiAccuracyAssessment;
use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\FactMaintenanceCheck;
use App\Models\FactMaintenanceResult;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProviderSpendRecord;
use App\Pipelines\Steps\AiAccuracy\CheckAccuracy;
use App\Pipelines\Steps\AiSampling\SampleAnswer;
use App\Pipelines\Steps\FactMaintenance\CheckPageFacts;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Reconcile durable provider evidence with the corresponding step, never add the same bill twice. */
final class ProviderSpend
{
    /** @return array{recovered_provider_micros:int, unknown_provider_attempts:int, ambiguous_provider_records:int, completeness:string, limitations:list<string>} */
    public static function for(Project $project, DateTimeInterface $since, ?DateTimeInterface $until): array
    {
        $runs = PipelineRun::acrossProjects()->where('project_id', $project->id)->whereIn('pipeline', ['ai_sample', 'ai_accuracy', 'fact_maintenance'])->get()->keyBy('id');
        $steps = PipelineStep::acrossProjects()->where('project_id', $project->id)->whereIn('pipeline_run_id', $runs->keys())->get()->groupBy('pipeline_run_id');
        $groups = [];
        $unknown = 0;
        $ambiguous = 0;
        $from = CarbonImmutable::instance($since);
        $to = $until === null ? null : CarbonImmutable::instance($until);
        // Use the step's accounting window when linked, including evidence recorded just after midnight.
        // This keeps adjacent reports additive and consistent with ProjectSpend's existing step totals.
        $add = function (?string $runId, string $pipeline, string $inputKey, string $inputId, string $stepKey, DateTimeInterface $at, ?int $cost, int $unknownCount = 0, bool $executionPinned = false) use ($runs, $steps, $from, $to, &$groups, &$unknown, &$ambiguous): void {
            $run = $runId === null ? null : $runs->get($runId);
            $matches = $runs->filter(fn (PipelineRun $candidate): bool => $candidate->pipeline === $pipeline && ($candidate->input[$inputKey] ?? null) === $inputId);
            if ($run === null && $matches->count() === 1) {
                $run = $matches->first();
            }
            $step = $run === null ? null : ($steps->get($run->id) ?? collect())->firstWhere('step_key', $stepKey);
            $when = $step === null ? CarbonImmutable::instance($at) : CarbonImmutable::parse((string) $step->getRawOriginal('created_at'));
            if ($when->lessThan($from) || ($to !== null && $when->greaterThanOrEqualTo($to))) {
                return;
            }
            $unknown += $unknownCount;
            if (! $executionPinned && $matches->count() > 1) {
                // Multiple legacy dispatches cannot establish which rollup includes this charge.
                $ambiguous++;

                return;
            }
            $key = $run === null ? 'unlinked:'.$pipeline.':'.$inputId : $run->id.':'.$stepKey;
            $groups[$key] ??= ['known' => 0, 'metered' => $step->cost_micros ?? 0];
            $groups[$key]['known'] += $cost ?? 0;
        };
        $answers = AiSamplingAnswer::acrossProjects()->where('project_id', $project->id)->get()->keyBy('sampling_cell_id');
        foreach (AiSamplingCell::acrossProjects()->where('project_id', $project->id)->whereNotNull('attempted_at')->get() as $cell) {
            $answer = $answers->get($cell->id);
            $executionId = $answer?->metadata['execution_run_id'] ?? null;
            $add(is_string($executionId) ? $executionId : $cell->pipeline_run_id, 'ai_sample', 'cell_id', $cell->id, SampleAnswer::key(), $cell->attempted_at, $answer?->total_cost_micros, $answer?->total_cost_micros === null ? 1 : 0, is_string($executionId));
        }
        $journalRuns = [];
        foreach (ProviderSpendRecord::acrossProjects()->where('project_id', $project->id)->get() as $record) {
            $journalRuns[$record->pipeline_run_id] = true;
            $run = $runs->get($record->pipeline_run_id);
            $key = $run?->pipeline === 'fact_maintenance' ? 'check_id' : 'assessment_id';
            $add($record->pipeline_run_id, $run->pipeline ?? 'ai_accuracy', $key, (string) ($run?->input[$key] ?? ''), $record->step_key, $record->created_at, $record->cost_micros, $record->cost_micros === null ? 1 : 0, true);
        }
        // Existing immutable results predate the per-call journal. Retain their real token evidence.
        $legacy = function (?string $runId, string $pipeline, string $inputKey, string $inputId, string $stepKey, DateTimeInterface $at, ?array $result) use ($runs, $journalRuns, $add): void {
            $run = $runId === null ? null : $runs->get($runId);
            $matches = $runs->filter(fn (PipelineRun $candidate): bool => $candidate->pipeline === $pipeline && ($candidate->input[$inputKey] ?? null) === $inputId);
            if ($run === null && $matches->count() === 1) {
                $run = $matches->first();
            }
            if (($run !== null && isset($journalRuns[$run->id])) || $matches->contains(fn (PipelineRun $candidate): bool => isset($journalRuns[$candidate->id]))) {
                return;
            }
            $calls = $result['checker']['calls'] ?? [];
            $attempted = (int) ($result['coverage']['attempted_calls'] ?? ($result === null ? 1 : count($calls)));
            $missing = max(0, $attempted - count($calls));
            $known = 0;
            foreach ($calls as $call) {
                $prices = $run === null ? [] : config('models.prices.versions.'.$run->price_list_version, []);
                $input = (int) ($call['input_tokens'] ?? 0);
                $output = (int) ($call['output_tokens'] ?? 0);
                if ($input + $output === 0 || ! isset($prices[$call['model'] ?? ''])) {
                    $missing++;
                } else {
                    $known += app(ModelCatalog::class)->cost($call['model'], $input, $output, $run->price_list_version);
                }
            }
            $add($runId, $pipeline, $inputKey, $inputId, $stepKey, $at, $known, $missing);
        };
        foreach (AiAccuracyAssessment::acrossProjects()->where('project_id', $project->id)->whereNotNull('started_at')->get() as $assessment) {
            $legacy($assessment->pipeline_run_id, 'ai_accuracy', 'assessment_id', $assessment->id, CheckAccuracy::key(), $assessment->started_at, $assessment->result);
        }
        $maintenance = FactMaintenanceResult::acrossProjects()->where('project_id', $project->id)->get()->keyBy('check_id');
        foreach (FactMaintenanceCheck::acrossProjects()->where('project_id', $project->id)->whereNotNull('attempted_at')->get() as $check) {
            $legacy($check->pipeline_run_id, 'fact_maintenance', 'check_id', $check->id, CheckPageFacts::key(), $check->attempted_at, $maintenance->get($check->id)?->assessment);
        }
        $recovered = array_sum(array_map(fn (array $group): int => max(0, (int) $group['known'] - (int) $group['metered']), $groups));

        return ['recovered_provider_micros' => $recovered, 'unknown_provider_attempts' => $unknown, 'ambiguous_provider_records' => $ambiguous,
            'completeness' => $unknown + $ambiguous === 0 ? 'complete' : 'incomplete',
            'limitations' => array_values(array_filter([
                $unknown > 0 ? 'Some provider attempts have no known price or outcome. Recorded cost is a lower bound.' : null,
                $ambiguous > 0 ? 'Some historical provider records have ambiguous pipeline links; their additional cost cannot be established.' : null,
                'Checker costs use reported tokens and the pinned price list; they are not reconciled provider invoices.',
                'Linked provider costs belong to the month when their pipeline step was created, which can differ from the provider charge date for work crossing a month boundary.',
            ]))];
    }
}
