<?php

declare(strict_types=1);

namespace Tests\Support\Pipelines;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/** One of two independent branches off the root. */
class LeftStep extends AbstractStep
{
    public static function key(): string
    {
        return 'left';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return ['root'];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::success();
    }
}
