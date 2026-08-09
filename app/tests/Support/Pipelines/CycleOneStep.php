<?php

declare(strict_types=1);

namespace Tests\Support\Pipelines;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/** Half of a cycle. */
class CycleOneStep extends AbstractStep
{
    public static function key(): string
    {
        return 'cycle_one';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return ['cycle_two'];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::success();
    }
}
