<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Visibility\Sampling\SamplingTiming;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SamplingTimingTest extends TestCase
{
    #[Test]
    public function the_deadline_covers_both_transport_calls_and_stale_detection_waits_for_the_grace_period(): void
    {
        config()->set('research.dataforseo.timeout', 60);
        config()->set('visibility.timeout', 150);
        config()->set('models.timeout', 300);
        config()->set('pipeline.stale_claim_grace', 60);
        $this->assertSame(360, SamplingTiming::stepSeconds());
        $this->assertSame(420, SamplingTiming::staleSeconds());
        $this->assertGreaterThan(60 + 150, SamplingTiming::stepSeconds());
        $this->assertGreaterThan(300, SamplingTiming::stepSeconds());

        config()->set('visibility.timeout', 300);
        $this->assertSame(420, SamplingTiming::stepSeconds());
        $this->assertSame(480, SamplingTiming::staleSeconds());
        config()->set('pipeline.stale_claim_grace', 90);
        $this->assertSame(510, SamplingTiming::staleSeconds());
    }

    #[Test]
    public function the_exact_stale_boundary_and_unknown_start_time_are_not_abandoned(): void
    {
        $this->freezeTime();
        $boundary = SamplingTiming::staleBefore();
        $this->assertFalse(SamplingTiming::isStale($boundary));
        $this->assertFalse(SamplingTiming::isStale(null));
        $this->assertTrue(SamplingTiming::isStale($boundary->subSecond()));
    }
}
