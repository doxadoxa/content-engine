<?php

declare(strict_types=1);

namespace Tests\Support\Pipelines;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/** Fan-in: waits for both branches. */
class JoinStep extends AbstractStep
{
    public static function key(): string
    {
        return 'join';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return ['left', 'right'];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::success();
    }
}
