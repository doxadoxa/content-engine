<?php

declare(strict_types=1);

namespace App\ContentStudio;

use App\Enums\PipelineRunStatus;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\ContentStudioPipeline;
use Illuminate\Support\Facades\Cache;

/** The one durable dispatch boundary shared by Studio and project onboarding. */
final class ContentStudioOperations
{
    public function __construct(private readonly PipelineRunner $pipelines) {}

    /**
     * Start one operation, unless the same one is already running.
     *
     * **The guard is scoped to what the operation acts on, not to the plan.**
     * It used to be the plan for everything, which was right while a plan could
     * only have one operation in flight: proposing, refining and generating all
     * write the same rows, and two at once is a lost edit.
     *
     * Drafting stopped being one operation. A week is now a run per idea —
     * because a single job doing five of them was a job whose duration grew
     * with the plan, and the timeout it would eventually hit was a real one. A
     * plan-wide guard collapses that fan-out on the spot: the second idea finds
     * the first one running and is handed it back instead of being started, so
     * a five-idea week produces one idea and reports success.
     *
     * So the subject is the plan for the operations that contend on the plan,
     * and the row itself for the two that do not: an idea being drafted, a
     * draft being redrawn. Two ideas of the same week genuinely do not
     * conflict — they write different rows — and the thing worth preventing is
     * the same one twice, which this still prevents.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(
        Project $project,
        ContentPlan $plan,
        ContentStudioAction $action,
        array $input = [],
    ): PipelineRun {
        // Which input names the thing this action works on, if it is not the
        // plan. Both of these arrive per row, and both were collapsing: two
        // drafts of one plan asking for pictures at the same moment would find
        // each other's run and the second would silently never be drawn.
        $subjectKey = match ($action) {
            ContentStudioAction::GenerateIdea => 'content_idea_id',
            ContentStudioAction::ReviseImage => 'content_item_id',
            default => null,
        };

        $subject = $subjectKey === null
            ? (string) $plan->getKey()
            : (string) ($input[$subjectKey] ?? $plan->getKey());

        return Cache::lock("content-studio:dispatch:{$subject}", 10)
            ->block(3, function () use ($project, $plan, $action, $input, $subject, $subjectKey): PipelineRun {
                $active = PipelineRun::query()
                    ->where('pipeline', ContentStudioPipeline::key())
                    ->whereIn('status', [PipelineRunStatus::Pending, PipelineRunStatus::Running])
                    ->where('input->'.($subjectKey ?? 'content_plan_id'), $subject)
                    // A per-idea run must not be handed back to a caller asking
                    // for a plan-level one, or refining a plan while a week is
                    // drafting would silently return the draft run and never
                    // refine anything.
                    ->where('input->action', $action->value)
                    ->latest()
                    ->first();

                if ($active !== null) {
                    return $active;
                }

                return $this->pipelines->start(
                    ContentStudioPipeline::key(),
                    $project,
                    [
                        'action' => $action->value,
                        'content_plan_id' => $plan->getKey(),
                        ...$input,
                    ],
                );
            });
    }
}
