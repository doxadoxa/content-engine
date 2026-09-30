<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Publishing\StrandedDeliveries;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two numbers in two files that have to stay in order (§9).
 *
 * `publish:sweep-stranded` re-dispatches a delivery that has sat unattempted
 * longer than {@see StrandedDeliveries}' threshold. Until the queue's own
 * `retry_after` has elapsed, that delivery's job may still be reserved by a
 * worker that is merely slow, so a threshold at or below `retry_after` sweeps a
 * running delivery and sends it twice.
 *
 * It has drifted before: the threshold was 1 800 s, chosen to clear a
 * `retry_after` of 1 200 s, and stayed there when `retry_after` went up to
 * 2 700 s. Deliveries now run on a connection of their own, so the number that
 * matters is the `publishing` connection's `retry_after`, not the pipeline's.
 * This pins the defaults the repository ships, read from the real config files
 * with `PUBLISH_STRANDED_AFTER` and `PUBLISH_QUEUE_RETRY_AFTER` hidden. An
 * installation that overrides either is its own arithmetic and must not fail
 * the suite — though the class floors the threshold against whatever
 * `retry_after` is actually in force, which the last test here holds it to.
 */
final class StrandedThresholdTest extends TestCase
{
    private const array OVERRIDES = ['PUBLISH_STRANDED_AFTER', 'PUBLISH_QUEUE_RETRY_AFTER'];

    #[Test]
    public function the_default_threshold_clears_the_default_retry_after(): void
    {
        $retryAfter = $this->shippedRetryAfter();
        $threshold = $this->shipped('publishing.php')['stranded_after'];

        $this->assertGreaterThan(
            $retryAfter,
            $threshold,
            "Redis re-delivers a reserved delivery at {$retryAfter}s, and publish:sweep-stranded "
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
        $this->assertGreaterThan($this->shippedRetryAfter(), StrandedDeliveries::AFTER_SECONDS);
    }

    #[Test]
    public function no_configuration_can_put_the_threshold_under_retry_after(): void
    {
        // Somebody raises `retry_after` and forgets the threshold, or sets the
        // threshold to something that looked sensible for a database queue.
        // Either way the sweep must still wait out the re-delivery.
        config([
            'queue.connections.publishing.retry_after' => 600,
            'publishing.stranded_after' => 120,
        ]);

        $this->assertGreaterThan(600, StrandedDeliveries::seconds());
    }

    /** The `retry_after` of the connection deliveries actually run on. */
    private function shippedRetryAfter(): int
    {
        $connection = $this->shipped('publishing.php')['connection'];

        return $this->shipped('queue.php')['connections'][$connection]['retry_after'];
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
