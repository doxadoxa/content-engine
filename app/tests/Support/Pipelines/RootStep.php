<?php

declare(strict_types=1);

namespace Tests\Support\Pipelines;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/** The only step with nothing to wait for. */
class RootStep extends AbstractStep
{
    public static function key(): string
    {
        return 'root';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::success();
    }
}
