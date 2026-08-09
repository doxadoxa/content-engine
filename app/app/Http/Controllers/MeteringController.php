<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Support\Metering\PostCostReport;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the engine costs (§7, and §9's "decide models on data, not feelings").
 *
 * Four readings, because they answer four different questions: per step ("is
 * the fact-check worth what it costs"), per pipeline ("is research or
 * generation the expensive one"), per day ("is it getting worse"), and — the
 * one the social spec adds — per *published post*.
 *
 * That last one is not a fifth slice of the same cake. §8 changes the unit:
 * "единица — опубликованный пост, а не сгенерированный", because the drafting
 * pipeline writes eight and keeps one and a report counting calls "соврёт в
 * разы". It is on this screen rather than on a new one because an operator
 * comparing the price of a post against the price of an article should not have
 * to hold two tabs open to do it — which is also §12's sixth exit criterion,
 * and the criterion says *separated from*, not *somewhere else than*.
 */
class MeteringController extends Controller
{
    public function __construct(private readonly CurrentProject $current) {}

    public function __invoke(Request $request): Response
    {
        $days = max(1, min(365, (int) ($request->query('days') ?? 30)));
        $since = now()->subDays($days);
        $project = $this->current->get();

        return Inertia::render('metering/index', [
            'days' => $days,
            'by_step' => $this->byStep($since),
            'by_pipeline' => $this->byPipeline($since),
            'trend' => $this->trend($since),
            'per_unit' => $this->perUnit($since),

            // §8, and not deferred, for the same reason as everything else on
            // this page: it is a handful of aggregates over the window the rest
            // of the screen already reads, and it is now the headline number
            // rather than a footnote to the others.
            'social' => $project instanceof Project
                ? PostCostReport::for($project, $since)->toArray()
                : null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byStep(\DateTimeInterface $since): array
    {
        // toBase(), because these rows are aggregates rather than steps —
        // hydrating them as models would invite somebody to save one.
        /** @var list<object{pipeline: string, step_key: string, runs: int, input_tokens: string, output_tokens: string, cost_micros: string, latency_ms: string|null}> $rows */
        $rows = PipelineStep::query()
            ->join('pipeline_runs', 'pipeline_runs.id', '=', 'pipeline_steps.pipeline_run_id')
            ->where('pipeline_runs.created_at', '>=', $since)
            ->groupBy('pipeline_runs.pipeline', 'pipeline_steps.step_key')
            ->orderByRaw('sum(pipeline_steps.cost_micros) desc')
            ->toBase()
            ->get([
                'pipeline_runs.pipeline',
                'pipeline_steps.step_key',
                DB::raw('count(*) as runs'),
                DB::raw('sum(pipeline_steps.input_tokens) as input_tokens'),
                DB::raw('sum(pipeline_steps.output_tokens) as output_tokens'),
                DB::raw('sum(pipeline_steps.cost_micros) as cost_micros'),
                DB::raw('round(avg(pipeline_steps.latency_ms)) as latency_ms'),
            ])
            ->all();

        return array_map(static fn (object $row): array => [
            'pipeline' => $row->pipeline,
            'step_key' => $row->step_key,
            'runs' => (int) $row->runs,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'cost_micros' => (int) $row->cost_micros,
            'latency_ms' => (int) $row->latency_ms,
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byPipeline(\DateTimeInterface $since): array
    {
        /** @var list<object{pipeline: string, runs: int, cost_micros: string}> $rows */
        $rows = PipelineRun::query()
            ->where('created_at', '>=', $since)
            ->groupBy('pipeline')
            ->orderByRaw('sum(cost_micros) desc')
            ->toBase()
            ->get(['pipeline', DB::raw('count(*) as runs'), DB::raw('sum(cost_micros) as cost_micros')])
            ->all();

        return array_map(static fn (object $row): array => [
            'pipeline' => $row->pipeline,
            'runs' => (int) $row->runs,
            'cost_micros' => (int) $row->cost_micros,
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trend(\DateTimeInterface $since): array
    {
        /** @var list<object{day: string, cost_micros: string, runs: int}> $rows */
        $rows = PipelineRun::query()
            ->where('created_at', '>=', $since)
            ->groupByRaw('date(created_at)')
            ->orderByRaw('date(created_at)')
            ->toBase()
            ->get([
                DB::raw('date(created_at) as day'),
                DB::raw('sum(cost_micros) as cost_micros'),
                DB::raw('count(*) as runs'),
            ])
            ->all();

        return array_map(static fn (object $row): array => [
            'day' => (string) $row->day,
            'cost_micros' => (int) $row->cost_micros,
            'runs' => (int) $row->runs,
        ], $rows);
    }

    /**
     * The number §6 of the spec actually cares about, and the one the Phase 0
     * exit criterion names: what an article costs.
     *
     * @return array<string, mixed>
     */
    private function perUnit(\DateTimeInterface $since): array
    {
        $runs = PipelineRun::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('content_item_id');

        $units = (int) $runs->clone()->distinct()->count('content_item_id');
        $spent = (int) $runs->clone()->sum('cost_micros');

        return [
            'units' => $units,
            'cost_micros' => $spent,
            'average_micros' => $units === 0 ? null : intdiv($spent, $units),
        ];
    }
}
