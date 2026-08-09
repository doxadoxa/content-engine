<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialPlan;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Social\Governor;

/**
 * Ask the governor before looking for anything to say (§4.3).
 *
 * First, and on its own branch of the graph, because the answer changes what
 * gathering is *for*: a throttled week has two slots and a higher bar, and a
 * gatherer that does not know that spends its budget ranking candidates nobody
 * will place.
 *
 * A step at all — rather than {@see PlaceSlots} calling the service inline —
 * because §7 requires the run to explain itself. The verdict lands in
 * `pipeline_steps.output`, so "why did this week only get two" is answerable
 * from the run months later, including the trailing reply rate that decided it
 * and how many days of measurement stood behind that number.
 *
 * No model call, and that is the rule rather than an economy: §4.3 wants the
 * deterministic guard deterministic.
 */
class ReadGovernor extends AbstractStep
{
    public function __construct(private readonly Governor $governor) {}

    public static function key(): string
    {
        return 'read_governor';
    }

    public function handle(StepContext $context): StepResult
    {
        $verdict = $this->governor->verdict($context->project);

        // Remembered as well as returned: these are the two numbers §12's third
        // exit criterion is about, and run context is what the operator's
        // summary reads without opening a step's payload.
        $context->remember('social_plan.reply_rate', $verdict->replyRate);
        $context->remember('social_plan.throttled', $verdict->throttled);
        $context->remember('social_plan.allowance', $verdict->weeklyAllowance);

        return StepResult::success(new GovernorPayload($verdict));
    }
}
