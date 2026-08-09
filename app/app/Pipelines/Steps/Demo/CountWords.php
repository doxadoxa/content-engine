<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Demo;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * The other parallel branch. Depends on the same step as
 * {@see SummariseTopic} and on nothing else, so the two are dispatched together
 * — and this one stays on the cheap queue while that one waits on a model.
 */
class CountWords extends AbstractStep
{
    use FailsOnDemand;

    public static function key(): string
    {
        return 'count_words';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [ReadBrief::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $this->failIfAsked($context, self::key());

        $brief = $context->output(ReadBrief::key(), BriefPayload::class);

        return StepResult::success(new WordCountPayload(
            words: count(preg_split('/\s+/', trim($brief->topic)) ?: []),
        ));
    }
}
