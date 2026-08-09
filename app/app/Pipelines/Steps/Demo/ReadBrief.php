<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Demo;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * The root of the DAG: reads the run input and the project's active brief.
 *
 * Depends on nothing, so it is the only step dispatched when the run starts;
 * everything else waits on it.
 */
class ReadBrief extends AbstractStep
{
    use FailsOnDemand;

    public static function key(): string
    {
        return 'read_brief';
    }

    public function handle(StepContext $context): StepResult
    {
        $this->failIfAsked($context, self::key());

        $brief = $context->project->brandBrief()->first();

        return StepResult::success(new BriefPayload(
            topic: (string) $context->get('topic'),
            // A project with no brief yet still runs; it just sounds like
            // nobody in particular, which is the honest default.
            tone: $brief === null ? 'neutral' : $brief->tone,
        ));
    }
}
