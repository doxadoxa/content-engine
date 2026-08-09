<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialPlan;

use App\Models\SocialPlan;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Illuminate\Support\Facades\Log;

/**
 * Write down what the week decided, and shout if the floor was missed (§4.3, §7).
 *
 * > Пол | ≥2/нед + ответы | присутствие не должно умирать: недобор — **алерт
 * > оператору**, а не тишина.
 *
 * Three readings of that line were possible and two of them are wrong. Silence
 * is wrong — §7 says a machine that says nothing is indistinguishable from a
 * broken one. Filling the gap is worse: an engine that writes a post because it
 * is short of two is the calendar-filling instinct §4.3 spends a paragraph
 * switching off, and it produces exactly the weak unit §1 says costs reach on
 * the *next* post. What is left is the one the spec asks for — tell the
 * operator, and let a person decide whether there was really nothing to say.
 *
 * So this step never creates a unit. It has no branch that could.
 *
 * The row it writes is the one §7's daily summary reads: what the governor
 * allowed, what the planner used, the reply rate that decided the ceiling, and
 * every refusal in the planner's own words. See the `social_plans` migration
 * for why that is a row rather than a `project_states` column, a signal or a
 * log line.
 */
class RecordPlan extends AbstractStep
{
    public static function key(): string
    {
        return 'record_plan';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [PlaceSlots::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $slots = $context->output(PlaceSlots::key(), PlannedSlotsPayload::class);

        // The verdict as the week ended up, not as it started. It already
        // counts whatever the project had published or scheduled before this
        // run as well as everything just placed, which is the only reading of
        // the floor that makes sense: §4.3 asks whether presence is alive this
        // week, not whether this particular run was productive.
        $verdict = $slots->verdict;

        $plan = SocialPlan::record($context->project, $verdict, count($slots->slotIds));

        foreach ($slots->refusals as $refusal) {
            $plan->note('empty_slot', $refusal);
        }

        if ($plan->hasAlert()) {
            // Logged as well as stored. The row is what §7's screen reads; the
            // log line is what an operator who has not opened the screen for a
            // week has a chance of noticing.
            Log::warning('Social presence is below the floor', [
                'project' => $context->project->slug,
                'week' => $plan->week_start->toDateString(),
                'planned' => $plan->planned,
                'floor' => $plan->floor,
            ]);
        }

        return StepResult::success(new PlanRecordPayload(
            planId: (string) $plan->getKey(),
            planned: $plan->planned,
            alert: $plan->alert,
        ));
    }
}
