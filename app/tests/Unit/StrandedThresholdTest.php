<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Publishing\StrandedDeliveries;
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
 * 2 700 s. This pins the defaults the repository ships — the Redis connection
 * Horizon runs, and the threshold that goes with it — read from the real config
 * files with `PUBLISH_STRANDED_AFTER` and `REDIS_QUEUE_RETRY_AFTER` hidden. An
 * installation that overrides either (a database queue retries after 90 s and
 * may sweep far sooner) is its own arithmetic, and must not fail the suite.
 */
final class StrandedThresholdTest extends TestCase
{
    private const array OVERRIDES = ['PUBLISH_STRANDED_AFTER', 'REDIS_QUEUE_RETRY_AFTER'];

    #[Test]
    public function the_default_threshold_clears_the_default_retry_after(): void
    {
        $retryAfter = $this->shipped('queue.php')['connections']['redis']['retry_after'];
        $threshold = $this->shipped('publishing.php')['stranded_after'];

        $this->assertGreaterThan(
            $retryAfter,
            $threshold,
            "Redis re-delivers a reserved job at {$retryAfter}s, and publish:sweep-stranded "
                ."treats a delivery as abandoned at {$threshold}s. It would re-dispatch a delivery "
                .'a worker is still running: raise the PUBLISH_STRANDED_AFTER default in '
                .'config/publishing.php.',
        );
    }

    #[Test]
    public function the_fallback_clears_it_too(): void
    {
        // Used when `publishing.stranded_after` is missing or not a number, so
        // it has to be safe on its own.
        $this->assertGreaterThan(
            $this->shipped('queue.php')['connections']['redis']['retry_after'],
            StrandedDeliveries::AFTER_SECONDS,
        );
    }

    /**
     * A config file as it reads with none of the overrides set.
     *
     * `env()` reads `$_ENV`, `$_SERVER` and `getenv()` on every call, so hiding
     * the names there for the length of one `require` is enough.
     *
     * @return array<string, mixed>
     */
    private function shipped(string $file): array
    {
        $saved = [];

        foreach (self::OVERRIDES as $name) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        try {
            return require config_path($file);
        } finally {
            foreach ($saved as $name => [$env, $server, $process]) {
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }

                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }

                if ($process !== false) {
                    putenv("{$name}={$process}");
                }
            }
        }
    }
}
