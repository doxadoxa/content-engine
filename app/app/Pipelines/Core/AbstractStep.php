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

    public function timeout(): int
    {
        return (int) config('pipeline.defaults.timeout', 300);
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
