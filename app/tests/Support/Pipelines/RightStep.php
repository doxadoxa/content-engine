<?php

declare(strict_types=1);

namespace Tests\Support\Pipelines;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/** The other branch. Same dependency, no relationship to Left. */
class RightStep extends AbstractStep
{
    public static function key(): string
    {
        return 'right';
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
