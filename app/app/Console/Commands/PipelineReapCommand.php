<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan pipeline:reap` — the floor under the engine.
 *
 * {@see PipelineRunner::resume()} has existed since the runner did, documented
 * as "pick a stalled run back up", and **nothing ever called it**. Every
 * recovery the engine can perform was reachable only by a human typing it, and
 * there was no command that typed it either. This is that caller and nothing
 * more: the judgement all lives in `resume()`, which releases only claims that
 * are stale against their own step's timeout and re-dispatches only what the
 * graph says is ready.
 *
 * What its absence cost, and why this file exists. On 2026-08-07 a `visibility`
 * run reached its third step and dispatched it to a worker whose config
 * predated the pipeline. The step threw, the queue called `failed()`, and the
 * failure handler threw the same exception before writing anything — so the run
 * stayed at `running` with a `pending` step nobody would ever deliver again.
 * Both halves are fixed now ({@see PipelineRunner::strand()}), but a lost
 * dispatch has a hundred other causes — a worker killed between two `afterCommit`
 * boundaries, a Redis flush, a queue drained during a deploy — and every one of
 * them leaves the same shape: a run nobody is working on and nobody will.
 * Without a sweep the recovery has to be *triggered* by the very delivery that
 * went missing.
 *
 * Re-dispatching is safe to do speculatively, which is what makes a blunt sweep
 * the right tool. A step is owned by the attempt that claims it, so a duplicate
 * delivery loses the compare-and-set and returns; the cost of reaping a run
 * that did not need it is one queue message.
 *
 * Every project, not only the active ones. A stuck run is stuck whatever the
 * project's status, and a paused project resumed next month should not come
 * back to two-day-old wreckage — the tick will not have touched it, so nothing
 * else would ever have noticed.
 */
class PipelineReapCommand extends Command
{
    protected $signature = 'pipeline:reap
        {--project= : Only this project, by slug or id}
        {--dry : List what would be resumed, resume nothing}';

    protected $description = 'Pick up pipeline runs that have stopped moving';

    public function handle(PipelineRunner $runner, CurrentProject $current): int
    {
        $handle = $this->option('project');

        $projects = Project::query()
            ->when(
                is_string($handle) && $handle !== '',
                fn ($query) => $query->where(fn ($q) => $q->where('slug', $handle)->orWhere('id', $handle)),
            )
            ->get();

        if ($projects->isEmpty()) {
            if (is_string($handle) && $handle !== '') {
                $this->components->error('No project with that slug or id.');

                return self::FAILURE;
            }

            // A fresh installation, not a fault. This runs every ten minutes.
            return self::SUCCESS;
        }

        $reaped = 0;

        foreach ($projects as $project) {
            // Per project with the tenant scope on, rather than one
            // `acrossProjects()` sweep: `resume()` has to run as the project
            // anyway — every table it touches is scoped — and a query that
            // spans tenants here would be a second place where that is decided.
            $reaped += $current->run($project, function () use ($project, $runner): int {
                $stalled = PipelineRun::query()->stalled()->get();

                $count = 0;

                foreach ($stalled as $run) {
                    $idleFor = $run->steps()->max('finished_at') ?? $run->created_at;

                    $this->line("  {$project->slug}: {$run->pipeline} has not moved since {$idleFor}");

                    if ($this->option('dry')) {
                        $count++;

                        continue;
                    }

                    try {
                        $runner->resume($run);
                        $count++;
                    } catch (Throwable $e) {
                        // One wedged run must not stop the sweep reaching the
                        // rest — the whole point of this command is the runs
                        // nobody is looking at, and they queue up behind each
                        // other exactly when something systemic is wrong.
                        Log::error('A stalled pipeline run could not be resumed', [
                            'run_id' => $run->getKey(),
                            'pipeline' => $run->pipeline,
                            'reason' => $e->getMessage(),
                        ]);
                    }
                }

                return $count;
            });
        }

        if ($reaped > 0) {
            $this->components->info("Picked up {$reaped} stalled run(s).");
        }

        return self::SUCCESS;
    }
}
