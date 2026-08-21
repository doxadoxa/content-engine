<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\ContentStudio;

use App\ContentStudio\ContentStudioAction;
use App\ContentStudio\ContentStudioAssistant;
use App\ContentStudio\ContentStudioException;
use App\ContentStudio\ContentStudioOperations;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\ErrorClassifier;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use Throwable;

/** Execute one Studio intent on the expensive queue and leave the result in domain rows. */
class ApplyContentStudioAction extends AbstractStep
{
    public function __construct(
        private readonly ContentStudioAssistant $assistant,
        private readonly ContentStudioOperations $operations,
        private readonly ErrorClassifier $errors,
    ) {}

    public static function key(): string
    {
        return 'apply_content_studio_action';
    }

    public function handle(StepContext $context): StepResult
    {
        $plan = ContentPlan::query()->whereKey((string) $context->get('content_plan_id'))->firstOrFail();
        $action = ContentStudioAction::from((string) $context->get('action'));

        try {
            $result = match ($action) {
                ContentStudioAction::Proposal => [
                    'version' => $this->assistant->initialProposal(
                        $context->project,
                        $plan->month,
                        $context,
                        $context->run->getKey(),
                    )->assistant_version,
                ],
                ContentStudioAction::Refine => [
                    'version' => $this->assistant->refine(
                        $plan,
                        (string) $context->get('message'),
                        (int) $context->get('expected_version'),
                        $context,
                        $context->run->getKey(),
                    )->assistant_version,
                ],
                ContentStudioAction::Generate => $this->fanOut($plan, $context),
                ContentStudioAction::GenerateIdea => $this->assistant->generateIdea(
                    ContentIdea::query()->whereKey((string) $context->get('content_idea_id'))->firstOrFail(),
                    $context,
                    $context->run->getKey(),
                    // Carried from the request that started the run, so a post
                    // written because of a signal points back at it. Null for
                    // every idea the assistant planned, which is most of them.
                    $context->get('signal_id') === null
                        ? null
                        : (string) $context->get('signal_id'),
                ),
                ContentStudioAction::ReviseImage => $this->assistant->reviseImage(
                    ContentItem::query()->whereKey((string) $context->get('content_item_id'))->firstOrFail(),
                    $context->get('instruction') === null ? null : (string) $context->get('instruction'),
                    (int) $context->get('variants', 1),
                    $context,
                ),
            };
        } catch (ContentStudioException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Deliberately says nothing about the cause: this message reaches
            // the browser through the operation payload and the dashboard's
            // failure card, and an upstream error body is not something to
            // hand a customer. The cause is not lost — the runner logs the
            // whole chain for whoever has to fix it.
            if ($this->errors->isRetryable($e)) {
                throw new RetryableStepFailure('The Content Studio provider is temporarily unavailable.', previous: $e);
            }

            throw new TerminalStepFailure('The Content Studio model action failed.', previous: $e);
        }

        $context->remember('content_studio.action', $action->value);
        $context->remember('content_studio.result', $result);

        return StepResult::success();
    }

    /**
     * One idea, not a week — with room to make a call at all.
     *
     * The number that used to live here — 1200, sized from six measured runs of
     * a four-idea week — was the right answer to the wrong shape. A deadline
     * that has to cover a whole batch is a deadline that grows with the plan,
     * and the ceiling moves every time somebody adds a post to the calendar.
     *
     * A run now drafts one idea: about 125 seconds of model calls and pictures
     * whatever the week holds. That was sized at 300, which looked like the
     * work plus room for a slow provider and was in fact a deadlock. A step
     * does not spend its budget on calls, it spends it on calls it is *allowed
     * to start*: {@see StepContext::refuseWithoutTimeToFinish()} refuses one
     * that could still be running at the deadline, so a step needs a whole
     * `models.timeout` in hand before the first one. With both numbers at 300
     * the very first call was refused, about 600ms in, three attempts running,
     * and the failure reached the dashboard as "the provider is temporarily
     * unavailable" over a provider nobody had asked anything.
     *
     * 600 — the expensive queue's own default — is 125 seconds of work with a
     * refusal threshold underneath it rather than on top of it, and it still
     * does not change when the plan does. The fan-out run costs a moment and
     * fits inside the same number without needing its own.
     */
    public function timeout(): int
    {
        return 600;
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    /**
     * Work out the next batch and start a run for each idea in it.
     *
     * A step that starts runs, which is unusual enough to say why. Drafting a
     * week used to be this one job, and its duration was the week's duration —
     * four ideas measured at 499 seconds against a 900-second deadline, with a
     * fuller plan heading straight for it. One run per idea makes every job
     * about 125 seconds whatever the calendar holds, isolates a provider
     * failure to the idea it happened on, and lets a retry finish what its
     * predecessor started instead of redoing the week.
     *
     * This half costs nothing and finishes in a moment: it reads which ideas
     * are next and dispatches. It stays a pipeline action rather than moving
     * into the controller because both callers — the Studio button and
     * onboarding — need the same answer, and a run is what the screen already
     * knows how to watch.
     *
     * @return array{ideas: int, from: string|null, until: string|null}
     */
    private function fanOut(ContentPlan $plan, StepContext $context): array
    {
        $batch = $this->assistant->nextBatch($plan, (bool) $context->get('initial', false));

        foreach ($batch['ideas'] as $idea) {
            $this->operations->start($context->project, $plan, ContentStudioAction::GenerateIdea, [
                'content_idea_id' => (string) $idea->getKey(),
            ]);
        }

        return [
            'ideas' => $batch['ideas']->count(),
            'from' => $batch['from'],
            'until' => $batch['until'],
        ];
    }
}
