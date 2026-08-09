<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Pipelines\Core\PipelineStepMutex;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PipelineStepMutexTest extends TestCase
{
    #[Test]
    public function only_one_handler_in_a_process_can_hold_a_step_until_it_releases(): void
    {
        $first = new PipelineStepMutex;
        $second = new PipelineStepMutex;

        $this->assertTrue($first->acquire('run-1', 'write-draft'));

        try {
            $this->assertFalse($second->acquire('run-1', 'write-draft'));
            $this->assertTrue($second->acquire('run-1', 'illustrate'));
            $second->release('run-1', 'illustrate');
        } finally {
            $first->release('run-1', 'write-draft');
        }

        $this->assertTrue($second->acquire('run-1', 'write-draft'));
        $second->release('run-1', 'write-draft');
    }
}
