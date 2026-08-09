<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Demo;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * One of the two parallel branches. The only step here that calls a model, so
 * it is the only one on the expensive queue — and the only one that puts
 * anything in the cost column.
 */
class SummariseTopic extends AbstractStep
{
    use FailsOnDemand;

    public static function key(): string
    {
        return 'summarise_topic';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [ReadBrief::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $this->failIfAsked($context, self::key());

        $brief = $context->output(ReadBrief::key(), BriefPayload::class);

        $answer = $context->ask(
            role: 'draft',
            prompt: "Summarise this topic in one sentence: {$brief->topic}",
            instructions: "Write in a {$brief->tone} tone.",
        );

        return StepResult::success(new SummaryPayload(
            summary: trim($answer->text),
            model: $answer->model,
        ));
    }
}
