<?php

declare(strict_types=1);

namespace App\Visibility\Sampling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** One clock for the worker, recovery, reporting and explicit recheck boundary. */
final class SamplingTiming
{
    public static function stepSeconds(): int
    {
        // A cold inventory GET precedes one live-answer POST; neither transport retries.
        // Preserve a full generic model-call budget too, as required by the expensive pool.
        $inventory = max(1, (int) config('research.dataforseo.timeout', 60));
        $answer = max(1, (int) config('visibility.timeout', 150));
        $model = max(1, (int) config('models.timeout', 300));

        return max(360, $inventory + $answer + 60, $model + 60);
    }

    public static function staleSeconds(): int
    {
        return self::stepSeconds() + max(60, (int) config('pipeline.stale_claim_grace', 60));
    }

    public static function staleBefore(): CarbonImmutable
    {
        // Persisted attempt times and SQL bindings use whole seconds. PHP readers must
        // use the same precision or they can mark the boundary stale a fraction early.
        return CarbonImmutable::now()->startOfSecond()->subSeconds(self::staleSeconds());
    }

    public static function isStale(?CarbonInterface $attemptedAt): bool
    {
        return $attemptedAt?->lessThan(self::staleBefore()) ?? false;
    }
}
