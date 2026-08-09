<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Health\StackHealth;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class StackHealthTest extends TestCase
{
    #[Test]
    public function a_reachable_queue_is_healthy_and_says_nothing(): void
    {
        $health = app(StackHealth::class)->check();

        $this->assertTrue($health['healthy']);
        // Nothing to tell the operator. The dashboard renders no strip at all
        // rather than a card confirming that things are normal.
        $this->assertNull($health['reason']);
    }

    #[Test]
    public function an_unreachable_queue_is_reported_rather_than_thrown(): void
    {
        // A dashboard that 500s because Redis is down tells the operator less
        // than one that says the engine cannot run work.
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

        $health = app(StackHealth::class)->check();

        $this->assertFalse($health['healthy']);
        $this->assertSame('The job queue is unreachable.', $health['reason']);
    }
}
