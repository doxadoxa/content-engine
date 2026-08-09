<?php

declare(strict_types=1);

namespace Tests\Support\Pipelines;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/** Depends on a step that is not in the pipeline. */
class DanglingStep extends AbstractStep
{
    public static function key(): string
    {
        return 'dangling';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return ['nobody'];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::success();
    }
}
