<?php

declare(strict_types=1);

namespace App\Pipelines\Jobs;

use App\Models\PipelineRun;
use App\Pipelines\Core\PipelineRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Executes one step of one run.
 *
 * The payload is two strings. No models, no outputs — a step that drafts three
 * thousand words queues exactly the same handful of bytes as one that reads a
 * flag, and everything else is loaded from the database by the step that needs
 * it. It also means the job survives a deploy that changes a model's shape.
 *
 * `$tries = 1` because retries belong to the runner, not the queue: only the
 * runner can tell a throttled provider from a validation error, and only it can
 * record the attempt on the step row either way (§3.1).
 *
 * The tenant travels in the job payload through Laravel's Context — see the
 * tenancy note in the README — so a step dispatched under project A runs under
 * project A whatever else the worker has been doing.
 */
class RunStepJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $runId,
        public string $stepKey,
    ) {}

    public function handle(PipelineRunner $runner): void
    {
        $run = PipelineRun::acrossProjects()->find($this->runId);

        if ($run === null) {
            // Deleted while the job sat in the queue. Nothing to do.
            return;
        }

        $runner->execute($run, $this->stepKey);
    }

    /**
     * Reached when the queue kills the job outright — a timeout, a worker
     * restart, anything that escaped the runner. The step is still holding a
     * claim nobody will release, so record the failure rather than leave the
     * run wedged.
     */
    public function failed(?Throwable $e): void
    {
        $run = PipelineRun::acrossProjects()->find($this->runId);

        if ($run === null) {
            return;
        }

        Log::error('A pipeline step job was killed', [
            'run_id' => $this->runId,
            'step' => $this->stepKey,
            'exception' => $e?->getMessage(),
        ]);

        app(PipelineRunner::class)->abandon(
            $run,
            $this->stepKey,
            $e ?? new RuntimeException('The step job was terminated by the queue.'),
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['pipeline', "run:{$this->runId}", "step:{$this->stepKey}"];
    }
}
