<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

use App\Pipelines\Contracts\Step;

/**
 * Defaults, so a concrete step only states what is interesting about it: its
 * key, its dependencies and handle().
 */
abstract class AbstractStep implements Step
{
    /** @return list<string> */
    public function dependsOn(): array
    {
        return [];
    }

    public function retries(): int
    {
        return (int) config('pipeline.defaults.retries', 3);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('pipeline.defaults.backoff', [10, 60, 300]);

        return $backoff;
    }

    /**
     * How long this step may run, defaulted from the queue it runs on.
     *
     * Per queue rather than one number, because the two pools are deliberately
     * an order of magnitude apart and a step's deadline is only meaningful
     * under its worker's. A single 300 sat above the cheap worker's 120, so
     * every step that did not override this was stopped by a signal instead of
     * failing with a reason. `PipelineTimeoutChainTest`
     * holds the relation.
     */
    public function timeout(): int
    {
        $perQueue = (array) config('pipeline.defaults.timeouts', []);

        return (int) ($perQueue[$this->queue()] ?? config('pipeline.defaults.timeout', 300));
    }

    public function queue(): string
    {
        return (string) config('pipeline.queues.cheap');
    }

    /**
     * The queue for steps that call a model.
     *
     * Worth stating rather than leaving to judgement: a step on the cheap queue
     * that turns out to take two minutes does not just take two minutes, it
     * occupies a worker that every quick step in every other run is queued
     * behind (§3.2).
     */
    protected function expensiveQueue(): string
    {
        return (string) config('pipeline.queues.expensive');
    }
}
