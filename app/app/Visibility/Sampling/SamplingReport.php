<?php

declare(strict_types=1);

namespace App\Visibility\Sampling;

use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Support\Tenancy\CurrentProject;

final class SamplingReport
{
    /** @return array<string, mixed> */
    public function get(?AiSamplingRun $selected = null): array
    {
        $set = AiSamplingSet::query()->orderByDesc('version')->first();
        $selected ??= AiSamplingRun::query()->orderByDesc('id')->first();
        $runs = AiSamplingRun::query()->orderByDesc('id')->limit(30)->get();

        $project = app(CurrentProject::class)->get();

        return ['set' => $set, 'allowance' => $project === null ? null : app(SamplingSchedule::class)->describe($project), 'selected' => $selected === null ? null : $this->run($selected),
            'runs' => $runs->map(fn (AiSamplingRun $run): array => ['id' => $run->id, 'sampling_set_id' => $run->sampling_set_id, 'status' => $run->status,
                'created_at' => $run->created_at->toIso8601String(), 'purpose' => $run->purpose])->all(),
            'method' => 'Recorded provider API answers to fixed questions. These are samples, not a census of customer activity or personalised consumer applications. Mentions and citations are separate observations.'];
    }

    /**
     * @return array{
     *     id: string, status: string, set_version: int, set_id: string,
     *     created_at: string, finished_at: string|null, expected_cells: int, answered_cells: int,
     *     status_counts: array<string, int>, discovery: array<string, int|float|null>,
     *     known_cost_micros: int|null, unknown_cost_cells: int,
     *     comparison: array<string, mixed>, cells: list<array<string, mixed>>
     * }
     */
    public function run(AiSamplingRun $run): array
    {
        $set = AiSamplingSet::query()->whereKey($run->sampling_set_id)->firstOrFail();
        $cells = AiSamplingCell::query()->where('sampling_run_id', $run->id)->orderBy('id')->get();
        $answers = AiSamplingAnswer::query()->whereIn('sampling_cell_id', $cells->modelKeys())->get()->keyBy('sampling_cell_id');
        $discovery = $cells->filter(fn (AiSamplingCell $cell): bool => $cell->specification['prompt']['purpose'] === 'discovery' && $cell->status === 'answered');
        $answered = $discovery->filter(fn (AiSamplingCell $cell): bool => $answers->has($cell->id));
        $mentions = $answered->filter(fn (AiSamplingCell $cell): bool => $answers[$cell->id]->mentioned_in_text === true)->count();
        $citations = $answered->filter(fn (AiSamplingCell $cell): bool => $answers[$cell->id]->cited_own_site === true)->count();
        $previous = AiSamplingRun::query()->where('sampling_set_id', $set->id)->where('id', '<', $run->id)->orderByDesc('id')->first();
        $comparison = ['available' => false, 'previous_run_id' => $previous?->id, 'reason' => 'Two completed runs with identical questions, response models and request context are required.'];
        if ($previous !== null && $previous->status === 'complete' && $run->status === 'complete') {
            $earlierCells = AiSamplingCell::query()->where('sampling_run_id', $previous->id)->get()->keyBy('cell_key');
            $earlierAnswers = AiSamplingAnswer::query()->whereIn('sampling_cell_id', $earlierCells->modelKeys())->get()->keyBy('sampling_cell_id');
            $same = $cells->count() === $earlierCells->count() && $cells->every(function (AiSamplingCell $cell) use ($answers, $earlierCells, $earlierAnswers): bool {
                $priorCell = $earlierCells->get($cell->cell_key);
                $prior = $priorCell === null ? null : $earlierAnswers->get($priorCell->id);
                $now = $answers->get($cell->id);

                return $cell->status === 'answered' && $priorCell?->status === 'answered' && $now !== null && $prior !== null
                    && $now->resolved_model !== null && $now->resolved_model === $prior->resolved_model
                    && ($now->metadata['sent_country'] ?? null) === ($prior->metadata['sent_country'] ?? null)
                    && ($now->metadata['web_search_reported'] ?? null) === true && ($prior->metadata['web_search_reported'] ?? null) === true;
            });
            if ($same) {
                $comparison = ['available' => true, 'previous_run_id' => $previous->id, 'reason' => 'These runs use the same recorded sampling configuration and returned models. Answers can still vary between samples.'];
            }
        }

        return ['id' => $run->id, 'status' => $run->status, 'set_version' => $set->version, 'set_id' => $set->id,
            'created_at' => $run->created_at->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(),
            'expected_cells' => $cells->count(), 'answered_cells' => $cells->where('status', 'answered')->count(), 'status_counts' => $cells->countBy('status')->all(),
            'discovery' => ['answered' => $answered->count(), 'mentions' => $mentions, 'citations' => $citations,
                'mention_rate' => $answered->isEmpty() ? null : round($mentions / $answered->count() * 100, 1), 'citation_rate' => $answered->isEmpty() ? null : round($citations / $answered->count() * 100, 1)],
            'known_cost_micros' => $answers->whereNotNull('total_cost_micros')->isEmpty() ? null : (int) $answers->sum('total_cost_micros'),
            'unknown_cost_cells' => $cells->filter(fn (AiSamplingCell $cell): bool => $cell->attempted_at !== null && ($answers->get($cell->id)?->total_cost_micros === null))->count(),
            'comparison' => $comparison,
            'cells' => array_values($cells->map(function (AiSamplingCell $cell) use ($answers): array {
                $answer = $answers->get($cell->id);
                $status = $cell->status === 'running' && SamplingTiming::isStale($cell->attempted_at) ? 'indeterminate' : $cell->status;

                return ['id' => $cell->id, 'specification' => $cell->specification, 'status' => $status,
                    'reason' => $status === 'indeterminate' ? ($cell->reason ?? 'The request has no recorded outcome. An explicit recheck is needed.') : $cell->reason,
                    'inventory_checked_at' => $cell->inventory_checked_at?->toIso8601String(),
                    'answer' => $answer === null ? null : ['id' => $answer->id, 'mentioned_in_text' => $answer->mentioned_in_text, 'cited_own_site' => $answer->cited_own_site,
                        'resolved_model' => $answer->resolved_model, 'received_at' => $answer->received_at->toIso8601String(), 'total_cost_micros' => $answer->total_cost_micros,
                        'sent_country' => $answer->metadata['sent_country'] ?? null, 'web_search_reported' => $answer->metadata['web_search_reported'] ?? null]];
            })->all())];
    }
}
