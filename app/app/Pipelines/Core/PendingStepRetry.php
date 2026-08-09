<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

use App\Pipelines\Jobs\RunStepJob;

/**
 * A retry that must be dispatched only after the current step mutex is free.
 *
 * The synchronous queue executes a dispatch inline. Dispatching from inside
 * the protected handler window makes that retry correctly refuse the mutex,
 * but then no queued copy remains and the run is stranded. Carrying the intent
 * out of the window keeps sync tests and real fast queues equivalent.
 */
final readonly class PendingStepRetry
{
    public function __construct(
        public string $runId,
        public string $stepKey,
        public string $queue,
        public int $delaySeconds,
    ) {}

    public function dispatch(): void
    {
        RunStepJob::dispatch($this->runId, $this->stepKey)
            ->afterCommit()
            ->onQueue($this->queue)
            ->delay(now()->addSeconds($this->delaySeconds));
    }
}
