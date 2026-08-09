<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * Whether the engine can currently do the work it is asked for.
 *
 * Deliberately one question with one answer, rather than a panel of statistics.
 * An earlier version of this reported the framework version, the pending
 * migration count and the queue depth on the operator's dashboard — none of
 * which an operator can act on, and all of which said "fine" in three different
 * ways on a normal day.
 *
 * What is left is the one thing that does belong on that page: from the phase
 * that adds pipelines onward, a dead queue and an empty schedule produce the
 * same dashboard, and only one of them is somebody's problem.
 */
final class StackHealth
{
    public function __construct(private readonly QueueFactory $queue) {}

    /**
     * @return array{healthy: bool, reason: string|null}
     */
    public function check(): array
    {
        $failure = $this->queueFailure();

        return [
            'healthy' => $failure === null,
            'reason' => $failure,
        ];
    }

    /**
     * Why the queue cannot accept work, in words an operator can repeat to
     * whoever runs the servers — not the driver's exception text.
     */
    private function queueFailure(): ?string
    {
        try {
            $this->queue->connection()->size();

            return null;
        } catch (Throwable) {
            // A dashboard that 500s because Redis is down tells the operator
            // less than one that says Redis is down.
            return 'The job queue is unreachable.';
        }
    }
}
