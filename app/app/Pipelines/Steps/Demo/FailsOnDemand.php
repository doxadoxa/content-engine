<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Demo;

use App\Pipelines\Core\StepContext;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;

/**
 * The demo pipeline's failure hook: `fail_at` in the run input names a step
 * key, and that step throws when it starts.
 *
 * It lives in the demo steps rather than in the engine on purpose. Exercising
 * retry and failure by reaching into the runner would test the runner's
 * internals; going in through the front door — start a run, let it fail —
 * tests what actually happens when a real step throws.
 */
trait FailsOnDemand
{
    protected function failIfAsked(StepContext $context, string $stepKey): void
    {
        if ($context->get('fail_at') !== $stepKey) {
            return;
        }

        throw match ($context->get('fail_with', 'retryable')) {
            'terminal' => new TerminalStepFailure("Step `{$stepKey}` was asked to fail terminally."),
            default => new RetryableStepFailure("Step `{$stepKey}` was asked to fail."),
        };
    }
}
