<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Console\Commands\PublishSweepStrandedCommand;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Carbon;

/**
 * How many delivery jobs are due and not yet taken by a worker (§9).
 *
 * What {@see PublishSweepStrandedCommand} asks before deciding that an overdue
 * row's job is gone: while jobs are waiting, one of them may be that row's,
 * and a row's age cannot tell a lost job from one stuck in a queue.
 *
 * Two places a due job waits on Redis, and both count:
 *
 *   - the ready list, which is what a worker takes from;
 *   - the delayed set, for a retry whose rung has come. Redis does not move
 *     those to the ready list by itself — a worker does it on its way to
 *     taking a job — so with Horizon down every retry that fell due is still
 *     sitting there, and the ready list alone looks empty.
 *
 * Retries whose rung is still ahead are not counted: they are not late, and
 * counting them would keep the sweep off for as long as anything is retrying.
 * Neither are jobs a worker has reserved; the delivery lock covers those.
 *
 * Deliveries only. Page operations share the lane's workers but have a queue
 * name of their own (`publishing.pages_queue`), so a batch of them does not
 * read as a delivery backlog.
 */
class PublishingBacklog
{
    public function __construct(private readonly QueueFactory $queues) {}

    public function waiting(?Carbon $now = null): int
    {
        $queue = $this->queues->connection((string) config('publishing.connection'));
        $name = (string) config('publishing.queue');

        $waiting = (int) $queue->pendingSize($name);

        if ($queue instanceof RedisQueue) {
            // The same key RedisQueue writes delayed jobs under, scored by the
            // Unix time each becomes available. Not cluster-aware, like the
            // rest of this installation's Redis.
            $waiting += (int) $queue->getConnection()->zcount(
                $queue->getQueue($name).':delayed',
                '-inf',
                (string) ($now ?? Carbon::now())->getTimestamp(),
            );
        }

        return $waiting;
    }
}
