<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Feedback;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * Where decayed articles used to be moved into `refreshing` (§9.2).
 *
 * It no longer moves anything. A rewrite of live text is new text, and a
 * change to a page that is already earning is proposed and reviewed as a page
 * improvement rather than started automatically from a dip in the numbers.
 * The step stays in the graph so the feedback run still says, in its own
 * record, why nothing was queued for a rewrite.
 */
class QueueRefresh extends AbstractStep
{
    public static function key(): string
    {
        return 'queue_refresh';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [DetectDegradation::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::skip('Performance changes require a reviewed page proposal, not an automatic rewrite.');
    }
}
