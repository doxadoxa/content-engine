<?php

declare(strict_types=1);

namespace Tests\Support\Pipelines;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/** The other half. */
class CycleTwoStep extends AbstractStep
{
    public static function key(): string
    {
        return 'cycle_two';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return ['cycle_one'];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::success();
    }
}
