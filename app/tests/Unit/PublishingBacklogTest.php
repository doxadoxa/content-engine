<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Publishing\PublishingBacklog;
use Closure;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the stranded sweep counts as "still coming" (§9).
 *
 * Against a real RedisQueue with only the Redis connection underneath it
 * faked, so the keys asked for are the ones Laravel writes, without a Redis
 * server in the suite.
 */
final class PublishingBacklogTest extends TestCase
{
    #[Test]
    public function a_retry_whose_time_has_come_counts_even_before_a_worker_moves_it(): void
    {
        $now = Carbon::parse('2026-09-30 12:00:00');
        $redis = $this->redis(ready: 1, due: 2);

        // With Horizon down nothing migrates due retries out of the delayed
        // set, so the ready list alone reads one while three are late.
        $this->assertSame(3, $this->backlog($redis)->waiting($now));

        // Only those due by now: a rung twelve hours away is not late.
        $this->assertSame(
            [['llen', 'queues:publishing'], ['zcount', 'queues:publishing:delayed', '-inf', (string) $now->getTimestamp()]],
            $redis->asked,
        );
    }

    #[Test]
    public function an_idle_lane_has_no_backlog(): void
    {
        $this->assertSame(0, $this->backlog($this->redis(ready: 0, due: 0))->waiting());
    }

    private function redis(int $ready, int $due): RecordingRedisConnection
    {
        return new RecordingRedisConnection($ready, $due);
    }

    private function backlog(Connection $redis): PublishingBacklog
    {
        $queue = new RedisQueue(new class($redis) implements RedisFactory
        {
            public function __construct(private readonly Connection $redis) {}

            public function connection($name = null): Connection
            {
                return $this->redis;
            }
        }, 'publishing');

        return new PublishingBacklog(new class($queue) implements QueueFactory
        {
            public function __construct(private readonly Queue $queue) {}

            public function connection($name = null): Queue
            {
                return $this->queue;
            }
        });
    }
}

/** A Redis connection that answers the two questions the backlog asks, and records them. */
final class RecordingRedisConnection extends Connection
{
    /** @var list<list<string>> */
    public array $asked = [];

    public function __construct(private readonly int $ready, private readonly int $due) {}

    /** @param  array<int, string>|string  $channels */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}

    public function isCluster(): bool
    {
        return false;
    }

    public function llen(string $key): int
    {
        $this->asked[] = ['llen', $key];

        return $this->ready;
    }

    public function zcount(string $key, string $min, string $max): int
    {
        $this->asked[] = ['zcount', $key, $min, $max];

        return $this->due;
    }
}
