<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Visibility;

use App\Models\LlmPrompt;
use App\Models\LlmVisibilityAnswer;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Visibility\BrandPresence;
use App\Visibility\Contracts\LlmVisibilityGateway;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Put every monitored prompt to every assistant and write down what came back.
 *
 * The expensive step, and the only one here that talks to anybody. Each answer
 * is a paid call to a third-party model and the count multiplies — prompts ×
 * locales × assistants — so there is a ceiling, and when it bites the run says
 * how many it dropped rather than reporting a partial measurement as a whole
 * one.
 *
 * One assistant failing does not fail the step. They are four separate vendors
 * with four separate outages, and a project should get the three answers it can
 * have rather than none.
 */
class AskAssistants extends AbstractStep
{
    public function __construct(private readonly LlmVisibilityGateway $assistants) {}

    public static function key(): string
    {
        return 'ask_assistants';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [GeneratePrompts::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function timeout(): int
    {
        // Each answer is documented at up to 120 seconds because the model
        // browses before replying, and this step makes many of them. The
        // default 300 would kill the job partway through a paid sweep and
        // retry it from the beginning, paying twice.
        return 1800;
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $this->assistants->isConfigured()) {
            // A skip, not a failure. This runs on a schedule against every
            // project, and a nightly failure at each unconnected one teaches
            // the operator to stop reading failures.
            return StepResult::skip('No assistant panel is configured.');
        }

        $platforms = $this->assistants->platforms();

        if ($platforms === []) {
            return StepResult::skip('No assistants are enabled to ask.');
        }

        $prompts = LlmPrompt::query()->where('is_active', true)->orderBy('locale')->orderBy('created_at')->get();

        if ($prompts->isEmpty()) {
            return StepResult::skip('This project has no prompts to ask.');
        }

        $today = now()->toDateString();
        $budget = max(1, (int) $context->get('max_answers', config('visibility.max_answers_per_run', 80)));

        $asked = 0;
        $recorded = 0;
        $skippedForBudget = 0;
        $declined = 0;
        $spent = 0.0;

        foreach ($prompts as $prompt) {
            foreach ($platforms as $platform) {
                // Idempotent by (prompt, platform, day). An engine tick that
                // overlaps the previous one must not pay twice for the same
                // answer, and the unique index would reject the second write
                // after the money was already spent.
                $existing = LlmVisibilityAnswer::query()
                    ->where('llm_prompt_id', $prompt->id)
                    ->where('platform', $platform)
                    ->whereDate('asked_on', $today)
                    ->exists();

                if ($existing) {
                    continue;
                }

                if ($asked >= $budget) {
                    $skippedForBudget++;

                    continue;
                }

                $asked++;

                try {
                    $answer = $this->assistants->ask($platform, $prompt->text, $context->project->market);
                } catch (Throwable $e) {
                    // One vendor being down is not four vendors being down.
                    Log::info('An assistant could not be reached', [
                        'platform' => $platform,
                        'prompt' => $prompt->id,
                        'reason' => $e->getMessage(),
                    ]);

                    continue;
                }

                if ($answer === null) {
                    $declined++;
                }

                $spent += $answer === null ? 0.0 : $answer->moneySpent;

                $recorded++;

                LlmVisibilityAnswer::query()->create([
                    'llm_prompt_id' => $prompt->id,
                    'platform' => $platform,
                    'model' => $answer?->model,
                    'asked_on' => $today,
                    // Null, not false: "declined to answer" is not "answered
                    // without us", and only the second belongs in a score.
                    'mentioned' => $answer === null ? null : BrandPresence::found(
                        $answer->text,
                        $answer->citations,
                        $context->project->name,
                        $context->project->website_url,
                    ),
                    'excerpt' => $answer === null ? null : mb_substr($answer->text, 0, 1500),
                    'citations' => $answer === null ? [] : $answer->citations,
                    'brands' => [],
                    'money_spent' => $answer === null ? 0.0 : $answer->moneySpent,
                ]);
            }
        }

        // Every single call threw. One vendor being down is handled above and
        // is not worth a failure; all of them being down is a different event —
        // usually one cause, usually ours, usually credentials or balance. Left
        // as a success it would write no answers and the screen would report
        // the last sweep's score as though it were today's.
        if ($asked > 0 && $recorded === 0) {
            throw new RetryableStepFailure(
                "Asked {$asked} questions and not one assistant answered. That is not four separate "
                .'outages — check the DataForSEO balance and credentials.'
            );
        }

        if ($skippedForBudget > 0) {
            // Named, because a silently truncated sweep reports a score that
            // reads as though it covered everything.
            Log::warning('The visibility budget stopped a sweep short', [
                'project' => $context->project->slug,
                'asked' => $asked,
                'skipped' => $skippedForBudget,
                'budget' => $budget,
            ]);
        }

        $context->remember('visibility.answers_asked', $asked);
        $context->remember('visibility.answers_declined', $declined);
        $context->remember('visibility.money_spent', round($spent, 6));
        $context->remember('visibility.skipped_for_budget', $skippedForBudget);

        return StepResult::success(new AnsweredPayload($asked, $declined, $skippedForBudget, round($spent, 6)));
    }
}
