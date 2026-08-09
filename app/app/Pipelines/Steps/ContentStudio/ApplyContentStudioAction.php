<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\ContentStudio;

use App\ContentStudio\ContentStudioAction;
use App\ContentStudio\ContentStudioAssistant;
use App\ContentStudio\ContentStudioException;
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
                ContentStudioAction::Generate => $this->generate($plan, $context),
            };
        } catch (ContentStudioException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($this->errors->isRetryable($e)) {
                throw new RetryableStepFailure('The Content Studio provider is temporarily unavailable.', previous: $e);
            }

            throw new TerminalStepFailure('The Content Studio model action failed.', previous: $e);
        }

        $context->remember('content_studio.action', $action->value);
        $context->remember('content_studio.result', $result);

        return StepResult::success();
    }

    public function timeout(): int
    {
        return 900;
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    /** @return array{created: int, from: string|null, until: string|null} */
    private function generate(ContentPlan $plan, StepContext $context): array
    {
        $result = $this->assistant->generateNextBatch(
            $plan,
            $context,
            $context->run->getKey(),
            initial: (bool) $context->get('initial', false),
        );

        return [
            'created' => $result['created'],
            'from' => $result['from'],
            'until' => $result['until'],
        ];
    }
}
