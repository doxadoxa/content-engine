<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AssistantMessage;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Support\Metering\PostCostReport;
use App\Support\Metering\ProjectSpend;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan pipeline:cost <project>` — exit criterion 4 of phase 3.
 *
 * §6 wants the cost of a unit, the breakdown by step and the trend for a
 * project. Phase 7 gives that a screen; until then this is where the numbers
 * that decide model choices (§9) are read from.
 *
 * The social spec's §8 adds a second unit rather than a second column, and it
 * is printed as its own block at the bottom: "единица — опубликованный пост, а
 * не сгенерированный". The per-unit line above divides by the content rows a
 * run touched, which is right for an article and wrong for a post by the
 * selection ratio — the drafting pipeline writes eight and keeps one. See
 * {@see PostCostReport}.
 */
class PipelineCostCommand extends Command
{
    protected $signature = 'pipeline:cost
        {project : Project slug or id}
        {--pipeline= : Only this pipeline}
        {--days=30 : How far back to look}';

    protected $description = 'What the pipelines cost for a project, broken down by step';

    public function handle(): int
    {
        $project = $this->resolveProject((string) $this->argument('project'));

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $pipelineOption = $this->option('pipeline');
        $pipeline = is_string($pipelineOption) ? $pipelineOption : null;

        // acrossProjects and an explicit where: a console command runs outside
        // a request and has no current tenant, so the scope would otherwise
        // fail closed and report that everything is free.
        $runs = PipelineRun::acrossProjects()
            ->where('project_id', $project->getKey())
            ->where('created_at', '>=', $since)
            ->when($pipeline, fn ($q, $key) => $q->where('pipeline', $key));

        if (! $runs->clone()->exists()) {
            $this->components->info("No pipeline runs for {$project->name} in the last {$days} days.");

            // Not a return, any more. A project that was only ever talked to
            // has spent real money and used to be reported as free, which is
            // the exact shape of the bug this whole phase exists to close.
            $this->assistant($project, $since, $pipeline);

            return self::SUCCESS;
        }

        $this->components->info("{$project->name} — last {$days} days");
        $this->newLine();

        $this->byStep($project->getKey(), $since, $pipeline);
        $this->perRun($runs->clone()->count(), (int) $runs->clone()->sum('cost_micros'));
        $this->perUnit($project->getKey(), $since, $pipeline);
        $this->assistant($project, $since, $pipeline);
        $this->publishedPost($project, $since, $pipeline);

        return self::SUCCESS;
    }

    /**
     * The second door's bill, and then the sum of both.
     *
     * Skipped when `--pipeline` narrows the report, for the reason
     * {@see publishedPost()} is: a conversation belongs to no pipeline, and
     * printing it under a filter that excludes it by construction would make
     * the total below wrong on purpose.
     */
    private function assistant(Project $project, \DateTimeInterface $since, ?string $pipeline): void
    {
        if ($pipeline !== null) {
            return;
        }

        $turns = AssistantMessage::acrossProjects()
            ->where('project_id', $project->getKey())
            ->where('created_at', '>=', $since)
            ->whereNotNull('provider');

        $count = (int) $turns->clone()->count();
        $spent = (int) $turns->clone()->sum('cost_micros');
        $spend = ProjectSpend::for($project, $since);

        $this->newLine();
        $this->components->info('Both doors — the engine, and the conversation');

        $this->components->twoColumnDetail(
            'Assistant turns',
            $count === 0
                ? '— (nobody talked to it in this window)'
                : $this->money($spent)." over {$count} turns, ".$this->money(intdiv($spent, $count)).' each',
        );

        $this->components->twoColumnDetail('Pipelines', $this->money($spend->pipelineMicros));
        $this->components->twoColumnDetail(
            '<options=bold>Everything this project cost</>',
            '<options=bold>'.$this->money($spend->totalMicros()).'</>',
        );
    }

    private function byStep(string $projectId, \DateTimeInterface $since, ?string $pipeline): void
    {
        /** @var list<object{step_key: string, runs: int, input_tokens: string, output_tokens: string, cost_micros: string, latency_ms: string|null}> $rows */
        $rows = PipelineStep::acrossProjects()
            ->whereIn('pipeline_run_id', PipelineRun::acrossProjects()
                ->select('id')
                ->where('project_id', $projectId)
                ->where('created_at', '>=', $since)
                ->when($pipeline, fn ($query, $key) => $query->where('pipeline', $key)))
            ->groupBy('step_key')
            ->orderByRaw('sum(cost_micros) desc')
            ->get([
                'step_key',
                DB::raw('count(*) as runs'),
                DB::raw('sum(input_tokens) as input_tokens'),
                DB::raw('sum(output_tokens) as output_tokens'),
                DB::raw('sum(cost_micros) as cost_micros'),
                DB::raw('round(avg(latency_ms)) as latency_ms'),
            ])
            ->all();

        $this->table(
            ['Step', 'Runs', 'In', 'Out', 'Avg ms', 'Cost'],
            array_map(fn (object $row): array => [
                $row->step_key,
                $row->runs,
                number_format((float) $row->input_tokens),
                number_format((float) $row->output_tokens),
                $row->latency_ms === null ? '—' : number_format((float) $row->latency_ms),
                $this->money((int) $row->cost_micros),
            ], $rows),
        );
    }

    private function perRun(int $runCount, int $totalMicros): void
    {
        $this->components->twoColumnDetail('Runs', (string) $runCount);
        $this->components->twoColumnDetail('Total', $this->money($totalMicros));
        $this->components->twoColumnDetail(
            'Average run',
            $runCount === 0 ? '—' : $this->money(intdiv($totalMicros, $runCount)),
        );
    }

    /**
     * Cost per content unit, which is the number §6 actually cares about: a run
     * is an implementation detail, and a unit may take several.
     */
    private function perUnit(string $projectId, \DateTimeInterface $since, ?string $pipeline): void
    {
        $runs = PipelineRun::acrossProjects()
            ->where('project_id', $projectId)
            ->where('created_at', '>=', $since)
            ->when($pipeline, fn ($query, $key) => $query->where('pipeline', $key))
            ->whereNotNull('content_item_id');

        $units = (int) $runs->clone()->distinct()->count('content_item_id');
        $spent = (int) $runs->clone()->sum('cost_micros');

        $this->components->twoColumnDetail(
            'Per content unit',
            $units === 0 ? '— (no run was about a unit yet)' : $this->money(intdiv($spent, $units))." over {$units} units",
        );
    }

    /**
     * §8's block: the published post, and the four lines under it.
     *
     * A second unit rather than a second slice of the first. `perUnit()` above
     * divides by content rows a run touched, which is the right answer for an
     * article and off by the selection ratio for a post — §4.3 writes eight and
     * keeps one, and §8 says a report counting calls "соврёт в разы".
     *
     * Skipped when `--pipeline` narrows the report, because this unit spans
     * pipelines by construction: the planning, the drafting and the picture are
     * three pipelines' worth of steps in one post's price, and filtering to one
     * of them would print a confident third of the truth.
     */
    private function publishedPost(Project $project, \DateTimeInterface $since, ?string $pipeline): void
    {
        if ($pipeline !== null) {
            return;
        }

        $report = PostCostReport::for($project, $since);

        $this->newLine();
        $this->components->info('§8 — the unit is the published post');

        $post = $report->post;
        $article = $report->article;

        $this->components->twoColumnDetail(
            'Per published post',
            $post['average_micros'] === null
                ? '— (nothing published in this window)'
                : $this->money((int) $post['average_micros'])." over {$post['published']} published",
        );

        // Beside it, never instead of it. §12's sixth exit criterion is that
        // the two are known and separate.
        $this->components->twoColumnDetail(
            'Per published article',
            $article['average_micros'] === null
                ? '— (nothing published in this window)'
                : $this->money((int) $article['average_micros'])." over {$article['published']} published",
        );

        if ($post['per_generation_micros'] !== null) {
            $this->components->twoColumnDetail(
                'One generation',
                $this->money((int) $post['per_generation_micros'])
                    ." × {$post['candidates']} written",
            );
        }

        $this->table(
            ['Line', 'Units', 'Per unit', 'Per post', 'Cost'],
            array_map(function (array $line): array {
                /** @var array{label: string, units: int, unit_label: string, per_unit_micros: int|null, per_day_micros: int|null, per_post_micros: int|null, standing: bool, cost_micros: int} $line */
                $perPost = $line['standing']
                    ? ($line['per_day_micros'] === null ? '—' : $this->money($line['per_day_micros']).'/day')
                    : ($line['per_post_micros'] === null ? '—' : $this->money($line['per_post_micros']));

                return [
                    $line['label'],
                    $line['units'].' '.$line['unit_label'],
                    $line['per_unit_micros'] === null ? '—' : $this->money($line['per_unit_micros']),
                    $perPost,
                    $this->money($line['cost_micros']),
                ];
            }, $report->lines),
        );
    }

    private function money(int $micros): string
    {
        return '$'.number_format($micros / 1_000_000, 4);
    }

    private function resolveProject(string $handle): ?Project
    {
        return Project::query()->where('slug', $handle)->first()
            ?? Project::query()->whereKey($handle)->first();
    }
}
