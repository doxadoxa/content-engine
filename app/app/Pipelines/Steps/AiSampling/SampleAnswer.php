<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\AiSampling;

use App\Billing\Entitlements;
use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\Project;
use App\Models\User;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Visibility\Accuracy\AccuracyAssessments;
use App\Visibility\BrandPresence;
use App\Visibility\Contracts\LlmVisibilityGateway;
use App\Visibility\LlmAnswer;
use App\Visibility\Sampling\AnswerAllowance;
use App\Visibility\Sampling\SamplingRuns;
use App\Visibility\Sampling\SamplingTiming;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SampleAnswer extends AbstractStep
{
    public function __construct(private readonly LlmVisibilityGateway $provider, private readonly SamplingRuns $runs) {}

    public static function key(): string
    {
        return 'sample_ai_answer';
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function timeout(): int
    {
        return SamplingTiming::stepSeconds();
    }

    public function handle(StepContext $context): StepResult
    {
        $cell = AiSamplingCell::query()->whereKey((string) $context->get('cell_id'))->firstOrFail();
        if ($cell->status !== 'queued') {
            if ($cell->status === 'running' && SamplingTiming::isStale($cell->attempted_at)) {
                // A late receipt may have completed this cell since the initial read.
                AiSamplingCell::query()->whereKey($cell->id)->where('status', 'running')->where('attempted_at', '<', SamplingTiming::staleBefore())
                    ->update(['status' => 'indeterminate', 'reason' => 'An earlier request did not record its outcome. Start an explicit recheck; automatic repeat spending is disabled.', 'finished_at' => now()]);
            }
            $this->runs->finish($cell->sampling_run_id);

            return StepResult::skip('This cell was already claimed or recorded.');
        }
        $spec = $cell->specification;
        $platform = $spec['platform']['platform'];
        try {
            if (! $this->provider->isConfigured()) {
                throw new TerminalStepFailure('The sampling provider is not connected.');
            }
            $catalog = Cache::remember('ai-model-inventory:'.$this->provider->name().':'.$platform, 300, fn (): array => ['checked_at' => now()->toIso8601String(), 'models' => $this->provider->models($platform)]);
            $selected = collect($catalog['models'])->firstWhere('model_name', $spec['platform']['model']);
            AiSamplingCell::query()->whereKey($cell->id)->where('status', 'queued')->update(['inventory' => ['selected' => $selected, 'models' => $catalog['models']], 'inventory_checked_at' => $catalog['checked_at']]);
            if (! is_array($selected) || ($selected['web_search_supported'] ?? false) !== true) {
                throw new TerminalStepFailure('The pinned model is unavailable or does not support web search. Save a reviewed sampling set with a supported model.');
            }
        } catch (Throwable $error) {
            // Another worker may have claimed this cell while this preflight read was in flight.
            AiSamplingCell::query()->whereKey($cell->id)->where('status', 'queued')->update(['status' => 'unavailable', 'reason' => $error instanceof TerminalStepFailure ? $error->getMessage() : 'The provider model inventory could not be verified. No answer was purchased.', 'finished_at' => now()]);
            app(AnswerAllowance::class)->release($context->project, $cell);
            $this->runs->finish($cell->sampling_run_id);

            return StepResult::skip('The provider could not be validated before purchase.');
        }
        $claimed = DB::transaction(function () use ($cell, $context): bool {
            $project = Project::query()->whereKey($context->project->id)->lockForUpdate()->firstOrFail();
            $locked = AiSamplingCell::query()->whereKey($cell->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'queued') {
                return false;
            }
            $entitlements = app(Entitlements::class);
            $entitlements->forget($context->project);
            $run = AiSamplingRun::query()->findOrFail($cell->sampling_run_id);
            $actor = $run->requested_by === null ? null : User::query()->find($run->requested_by);
            if (! $entitlements->for($context->project)->mayGenerate() || ($run->requested_by !== null && ! ($actor?->projects()->whereKey($context->project->id)->wherePivot('role', 'owner')->exists() ?? false))) {
                $locked->update(['status' => 'unavailable', 'reason' => 'The project or requesting owner is no longer eligible to start paid work. No answer was purchased.', 'finished_at' => now()]);
                app(AnswerAllowance::class)->release($project, $locked);

                return false;
            }
            if (! app(AnswerAllowance::class)->attempt($project, $locked)) {
                $locked->update(['status' => 'unavailable', 'reason' => 'This check no longer has a current billing-period reservation. No answer was purchased.', 'finished_at' => now()]);

                return false;
            }
            $locked->update(['status' => 'running', 'attempted_at' => now(), 'pipeline_run_id' => $context->run->id]);

            return true;
        });
        if (! $claimed) {
            $this->runs->finish($cell->sampling_run_id);

            return StepResult::skip('Another worker claimed this cell.');
        }
        try {
            $answer = $this->provider->ask($platform, $spec['prompt']['text'], $spec['market'], [...$spec['platform'], 'preserve_empty' => true, 'tag' => $cell->id])
                ?? new LlmAnswer($platform, $spec['platform']['model'], '', [], 0.0, [], ['completion_state' => 'no_answer']);
            $text = $answer->text;
            $sections = $answer->sections ?: ($text === '' ? [] : [['text' => $text, 'annotations' => []]]);
            $empty = trim($text) === '';
            $total = $answer->totalCost === null ? null : (int) round($answer->totalCost * 1_000_000);
            DB::transaction(function () use ($cell, $spec, $answer, $text, $sections, $empty, $total, $context): void {
                AiSamplingAnswer::query()->firstOrCreate(['sampling_cell_id' => $cell->id], [
                    'full_text' => $text, 'sections' => $sections, 'citations' => $answer->citations,
                    'metadata' => [...$answer->metadata, 'execution_run_id' => $context->run->id],
                    'content_hash' => hash('sha256', json_encode($sections, JSON_THROW_ON_ERROR)),
                    'mentioned_in_text' => $empty ? null : BrandPresence::namesBrand($text, $spec['brand']),
                    'cited_own_site' => $empty ? null : BrandPresence::citesSite($answer->citations, $spec['website']),
                    'resolved_model' => $answer->metadata['resolved_model'] ?? ($this->provider->name() === 'fake' ? $answer->model : null),
                    'received_at' => now(), 'total_cost_micros' => $total,
                ]);
                $cell->update(['status' => $empty ? 'empty' : 'answered', 'reason' => $empty ? 'No final answer text was returned.' : null, 'finished_at' => now()]);
            });
            if ($total !== null) {
                $context->spend($total, $this->provider->name(), $answer->model);
            }
        } catch (Throwable $error) {
            $cell->update(['status' => $error instanceof TerminalStepFailure ? 'failed' : 'indeterminate',
                'reason' => $error instanceof TerminalStepFailure ? $error->getMessage() : 'The request outcome is unknown. It may have incurred provider cost. An explicit recheck is required.', 'finished_at' => now()]);
        }
        $this->runs->finish($cell->sampling_run_id);
        try {
            app(AccuracyAssessments::class)->recheckRun($cell->sampling_run_id);
        } catch (Throwable) {
            // Preserve the recorded answer; the separate safe reconciler retries only the missing dispatch.
            Log::warning('A recorded correction recheck is waiting for accuracy dispatch.', ['sampling_run_id' => $cell->sampling_run_id]);
        }

        return StepResult::success();
    }
}
