<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Publishing\StrandedDeliveries;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two numbers in two files that have to stay in order (§9).
 *
 * `publish:sweep-stranded` re-dispatches a delivery that has sat at `pending`
 * longer than {@see StrandedDeliveries}' threshold. Until the queue's own
 * `retry_after` has elapsed, that delivery's job may still be reserved by a
 * worker that is merely slow, so a threshold at or below `retry_after` sweeps a
 * running delivery and publishes the same article twice.
 *
 * It has already drifted once: the threshold was 1 800 s, chosen to clear a
 * `retry_after` of 1 200 s, and stayed there when `retry_after` went up to
 * 2 700 s. This reads both from the real config, so raising one without the
 * other fails here.
 */
final class StrandedThresholdTest extends TestCase
{
    #[Test]
    public function the_stranded_threshold_clears_the_queues_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertGreaterThan(
            $retryAfter,
            $this->thresholdInForce(),
            "Redis re-delivers a reserved job at {$retryAfter}s, and publish:sweep-stranded "
                .'treats a delivery as abandoned before that. It would re-dispatch a delivery a '
                .'worker is still running: raise PUBLISH_STRANDED_AFTER in config/publishing.php.',
        );
    }

    #[Test]
    public function the_fallback_clears_it_too(): void
    {
        // Used when `publishing.stranded_after` is missing or not a number, so
        // it has to be safe on its own.
        $this->assertGreaterThan(
            (int) config('queue.connections.redis.retry_after'),
            StrandedDeliveries::AFTER_SECONDS,
        );
    }

    /** The threshold as the sweeper sees it, config and floor included. */
    private function thresholdInForce(): int
    {
        $now = Carbon::parse('2026-08-09 12:00:00');

        return (int) StrandedDeliveries::cutoff($now)->diffInSeconds($now, absolute: true);
    }
}
