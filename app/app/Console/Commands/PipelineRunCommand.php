<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Pipelines\Core\PipelineRegistry;
use App\Pipelines\Core\PipelineRunner;
use Illuminate\Console\Command;

/**
 * `php artisan pipeline:run demo <project> --input=topic=…`
 *
 * How a pipeline is started by hand: the smoke test on a new machine, and the
 * way phases 4 and 5 will exercise their steps before anything schedules them.
 */
class PipelineRunCommand extends Command
{
    protected $signature = 'pipeline:run
        {pipeline : Pipeline key, e.g. demo}
        {project : Project slug or id}
        {--input=* : key=value pairs passed as the run input}
        {--wait : Poll until the run settles, for a queue you are watching}';

    protected $description = 'Start a pipeline run for a project';

    public function handle(PipelineRunner $runner, PipelineRegistry $registry): int
    {
        $key = (string) $this->argument('pipeline');

        if (! $registry->has($key)) {
            $this->components->error("No pipeline `{$key}`. Known: ".implode(', ', array_keys($registry->all())).'.');

            return self::FAILURE;
        }

        $project = Project::query()->where('slug', $this->argument('project'))->first()
            ?? Project::query()->whereKey($this->argument('project'))->first();

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $input = $this->runInput();

        // Lifted out of the input and onto the run row, because that column is
        // what joins a run to the unit it was about — and the per-unit cost
        // report, which §6 calls the number that matters, reads the column and
        // not the input. A run started here without this looks like it was
        // about nothing, and its cost silently leaves the average.
        $contentItemId = $input['content_item_id'] ?? null;

        $run = $runner->start(
            $key,
            $project,
            $input,
            is_string($contentItemId) && $contentItemId !== '' ? $contentItemId : null,
        );

        $this->components->info("Started {$key} for {$project->name}.");
        $this->components->twoColumnDetail('Run', $run->getKey());
        // acrossProjects, because a console command has no current tenant and
        // the scope fails closed — this printed "0" for a run with four steps.
        $this->components->twoColumnDetail('Steps', (string) PipelineStep::acrossProjects()
            ->where('pipeline_run_id', $run->getKey())
            ->count());

        if (! $this->option('wait')) {
            $this->newLine();
            $this->line('  Follow it at /horizon, then: <fg=gray>php artisan pipeline:cost '.$project->slug.'</>');

            return self::SUCCESS;
        }

        return $this->waitFor($run->getKey());
    }

    /** @return array<string, mixed> */
    private function runInput(): array
    {
        $input = [];

        /** @var list<string> $pairs */
        $pairs = $this->option('input');

        foreach ($pairs as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $input[$key] = $value;
        }

        return $input;
    }

    private function waitFor(string $runId): int
    {
        $deadline = now()->addMinutes(10);

        while (now()->lessThan($deadline)) {
            $run = PipelineRun::acrossProjects()->findOrFail($runId);

            if ($run->isTerminal()) {
                $this->newLine();
                $this->components->twoColumnDetail('Status', $run->status->label());
                $this->components->twoColumnDetail('Cost', '$'.number_format($run->costInDollars(), 4));

                if ($run->failed_step_key !== null) {
                    $this->components->error("Failed at `{$run->failed_step_key}`: ".($run->error['message'] ?? ''));

                    return self::FAILURE;
                }

                return self::SUCCESS;
            }

            usleep(500_000);
        }

        $this->components->warn('Still running after ten minutes; leaving it to the queue.');

        return self::SUCCESS;
    }
}
