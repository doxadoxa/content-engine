<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Demo;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * The fan-in: waits for both branches and reads both their payloads.
 *
 * This is the step that proves the DAG rather than a coincidence of ordering —
 * it cannot run until the slow branch and the fast branch have both settled,
 * and it reads each one's output by name and type.
 */
class AssembleResult extends AbstractStep
{
    use FailsOnDemand;

    public static function key(): string
    {
        return 'assemble_result';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [SummariseTopic::key(), CountWords::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $this->failIfAsked($context, self::key());

        $summary = $context->output(SummariseTopic::key(), SummaryPayload::class);
        $words = $context->output(CountWords::key(), WordCountPayload::class);

        $context->remember('demo.finished', true);

        return StepResult::success(new ResultPayload(
            headline: $summary->summary,
            words: $words->words,
        ));
    }
}
