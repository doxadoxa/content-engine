<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

use App\Ai\Contracts\ModelGateway;
use App\Ai\ModelCatalog;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Models\PipelineRun;
use App\Models\PipelineStep as StepRecord;
use App\Models\Project;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Events\PipelineRunFinished;
use App\Pipelines\Exceptions\InvalidPipelineDefinition;
use App\Pipelines\Jobs\RunStepJob;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The engine (§3.2).
 *
 * Three ideas hold it up.
 *
 * **State lives in the database.** A run is its rows. The runner holds nothing
 * between steps, so a worker can be killed at any instant and the next delivery
 * picks the run up from the rows — which is exit criterion 2 and not something
 * you can retrofit onto an in-memory scheduler.
 *
 * **A step is owned by exactly one attempt.** `attempt` on the step row is an
 * optimistic-lock version: claiming is `where attempt = N` → `set attempt = N+1`
 * and one affected row means it is ours. A PostgreSQL session advisory mutex
 * surrounds the handler and its guarded result write, so a stale replacement
 * cannot repeat external side effects while the original process is alive.
 * The database releases that mutex if the worker process dies.
 *
 * **Readiness is recomputed, never remembered.** After every settled step the
 * runner asks the graph what may now start. Nobody records "next"; two steps
 * that become ready together are dispatched together, which is the whole of
 * §3.2's parallelism.
 */
class PipelineRunner
{
    public function __construct(
        private readonly PipelineRegistry $registry,
        private readonly ErrorClassifier $errors,
        private readonly ModelCatalog $catalog,
        private readonly CurrentProject $currentProject,
        private readonly PipelineStepMutex $mutex,
    ) {}

    /**
     * Create a run and dispatch everything that can start at once.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(string $pipelineKey, Project $project, array $input = [], ?string $contentItemId = null): PipelineRun
    {
        $definition = $this->registry->get($pipelineKey);
        $graph = StepGraph::for($definition);

        // Before anything is queued: a run that cannot succeed should fail
        // where the caller is still standing, not four steps in on a worker.
        Validator::make($input, $definition->inputRules())->validate();

        $run = $this->currentProject->run($project, function () use ($definition, $graph, $input, $contentItemId): PipelineRun {
            return DB::transaction(function () use ($definition, $graph, $input, $contentItemId): PipelineRun {
                // No project_id here, and none on the step rows below: this
                // whole closure runs inside CurrentProject::run(), so
                // BelongsToProject stamps the tenant on the way in. Passing it
                // as well would be a second source of truth for the one thing
                // the tenancy layer exists to be the only source of.
                $run = PipelineRun::query()->create([
                    'content_item_id' => $contentItemId,
                    'pipeline' => $definition::key(),
                    'pipeline_version' => $definition::version(),
                    'status' => PipelineRunStatus::Pending,
                    'input' => $input,
                    'context' => [],
                    // Pinned now, so re-pricing later publishes a new number
                    // knowingly instead of restating this run's history (§3.4).
                    'price_list_version' => $this->catalog->priceListVersion(),
                ]);

                // Every step gets its row up front. The alternative — creating
                // rows as steps become ready — means a run that dies early has
                // no record of what it was going to do.
                foreach ($graph->order() as $position => $key) {
                    StepRecord::query()->create([
                        'pipeline_run_id' => $run->getKey(),
                        'step_key' => $key,
                        'position' => $position,
                        'status' => PipelineStepStatus::Pending,
                    ]);
                }

                return $run;
            });
        });

        $this->dispatchReady($run);

        return $run;
    }

    /**
     * Dispatch every step whose dependencies have settled.
     *
     * Safe to call from anywhere, including twice at once: dispatching is only
     * a queue message, and the claim decides who actually runs.
     */
    public function dispatchReady(PipelineRun $run): void
    {
        $this->forRun($run, function () use ($run): void {
            $this->dispatchReadyUnderTenant($run);
        });
    }

    /**
     * Run one step of one run. The entry point of {@see RunStepJob}.
     */
    public function execute(PipelineRun $run, string $stepKey): void
    {
        $this->forRun($run, function () use ($run, $stepKey): void {
            $this->executeUnderTenant($run, $stepKey);
        });
    }

    /**
     * Called when the queue kills a job outright — a timeout, a worker
     * restarted mid-step, an exception that escaped execute().
     *
     * The step is still sitting in `running` holding a claim nobody will
     * release, so it is failed here. It counts as an attempt, which is what
     * makes a step that reliably kills its worker eventually stop rather than
     * being re-dispatched forever.
     */
    public function abandon(PipelineRun $run, string $stepKey, Throwable $e): void
    {
        $this->forRun($run, function () use ($run, $stepKey, $e): void {
            $this->abandonUnderTenant($run, $stepKey, $e);
        });
    }

    /**
     * Pick a stalled run back up.
     *
     * Anything left `running` past its timeout plus the grace period is handed
     * back to `pending`, so the ordinary scheduler can claim it again. Steps
     * that already succeeded are untouched, which is what makes resuming free
     * of double work.
     */
    public function resume(PipelineRun $run): PipelineRun
    {
        $this->forRun($run, function () use ($run): void {
            $this->resumeUnderTenant($run);
        });

        return $run;
    }

    private function dispatchReadyUnderTenant(PipelineRun $run): void
    {
        if ($this->stoppedStatus($run->getKey())?->isTerminal() === true) {
            return;
        }

        $graph = $this->registry->graph($run->pipeline);

        /** @var Collection<int, StepRecord> $records */
        $records = $run->steps()->get();

        /** @var list<string> $settled */
        $settled = $records->filter(fn (StepRecord $r): bool => $r->status->isSettled())
            ->map(fn (StepRecord $r): string => $r->step_key)->values()->all();

        /** @var list<string> $pending */
        $pending = $records->filter(fn (StepRecord $r): bool => $r->status === PipelineStepStatus::Pending)
            ->map(fn (StepRecord $r): string => $r->step_key)->values()->all();

        $running = $records->contains(fn (StepRecord $r): bool => $r->status === PipelineStepStatus::Running);

        if ($pending === [] && ! $running) {
            $this->complete($run);

            return;
        }

        foreach ($graph->ready($pending, $settled) as $key) {
            $step = $graph->step($key);

            RunStepJob::dispatch($run->getKey(), $key)
                ->afterCommit()
                ->onQueue($step->queue());
        }
    }

    private function executeUnderTenant(PipelineRun $run, string $stepKey): void
    {
        if (! $this->mutex->acquire($run->getKey(), $stepKey)) {
            return;
        }

        $retry = null;

        try {
            $retry = $this->executeWhileLocked($run, $stepKey);
        } finally {
            $this->mutex->release($run->getKey(), $stepKey);
        }

        $retry?->dispatch();
    }

    private function executeWhileLocked(PipelineRun $run, string $stepKey): ?PendingStepRetry
    {
        if ($this->stoppedStatus($run->getKey())?->isTerminal() === true) {
            return null;
        }

        $graph = $this->registry->graph($run->pipeline);
        $step = $graph->step($stepKey);

        $record = $run->steps()->where('step_key', $stepKey)->first();

        if ($record === null) {
            Log::warning('A step was dispatched for a run that has no row for it', [
                'run_id' => $run->getKey(),
                'step' => $stepKey,
            ]);

            return null;
        }

        // Dependencies may still be open if this delivery arrived early, or if
        // the step was re-dispatched by a sibling branch. Nothing to do; the
        // step that settles last will dispatch it.
        if ($graph->ready([$stepKey], $this->settledKeys($run)) === []) {
            return null;
        }

        $claimed = $this->claim($record, $step);

        if ($claimed === null) {
            return null;
        }

        $this->markRunStarted($run);

        $project = $this->currentProject->get();

        $context = new StepContext(
            run: $run,
            // From the tenancy service rather than `$run->project`: forRun() has
            // just resolved it, and reading the relation would be a lazy load
            // the application is configured to refuse.
            project: $project ?? $run->project,
            input: $run->input,
            dependencyOutputs: $this->dependencyOutputs($run, $graph, $stepKey),
            runContext: $run->context,
            gateway: app(ModelGateway::class),
        );

        try {
            $result = $step->handle($context);
        } catch (Throwable $e) {
            return $this->fail($run, $record, $claimed, $step, $context, $e);
        }

        $this->succeed($run, $record, $claimed, $context, $result);

        return null;
    }

    private function abandonUnderTenant(PipelineRun $run, string $stepKey, Throwable $e): void
    {
        $record = $run->steps()->where('step_key', $stepKey)->first();

        if ($record === null || $record->status->isSettled()) {
            return;
        }

        $graph = $this->graphOrNull($run);

        $step = $graph?->has($stepKey) === true ? $graph->step($stepKey) : null;

        // The graph is what could not be built, which means the graph is also
        // what killed the step: an unregistered pipeline, a step the definition
        // no longer lists, a worker still holding last release's config. This
        // is the whole reason the method exists, so it must not be the one
        // thing it cannot survive.
        //
        // What it cost when it could not: on 2026-08-07 a `visibility` run was
        // dispatched to a worker whose config predated the pipeline. The step
        // threw `No pipeline `visibility` is registered`, the queue called
        // failed(), and *this* line threw the identical exception before
        // writing anything. The run sat at `running` for two days with its
        // third step at `pending`, the dashboard drew a spinner nobody could
        // clear, and `EngineTickCommand::isBusy()` — which treats any live run
        // in the contour as a reason to wait — drafted no article for the whole
        // of it. A failure handler that fails the same way the step did leaves
        // no trace anywhere the engine looks.
        if ($step === null) {
            $this->strand($run, $record, $e);

            return;
        }

        $this->fail($run, $record, $record->attempt, $step, null, $e)?->dispatch();
    }

    /**
     * Settle a step and its run with no idea what the step was.
     *
     * The retry policy, the backoff and the queue all live on the step object,
     * so without one there is nothing to retry *onto* — and a run whose graph
     * cannot be built could not finish however many times it were tried. It is
     * failed here with the original error, which is the outcome an operator can
     * see and act on rather than the one that hides.
     */
    private function strand(PipelineRun $run, StepRecord $record, Throwable $e): void
    {
        Log::error('A pipeline step could not be resolved to fail cleanly, so its run was failed', [
            'run_id' => $run->getKey(),
            'pipeline' => $run->pipeline,
            'step' => $record->step_key,
            'exception' => $e->getMessage(),
        ]);

        $wrote = $this->writeClaimed($record, $record->attempt, [
            'status' => PipelineStepStatus::Failed->value,
            'finished_at' => now(),
            'latency_ms' => $this->elapsedMs($record),
            'error' => $this->errors->describe($e),
        ]);

        // Somebody else owns the step now, which means the run is moving after
        // all. Failing it here would kill work that is in hand.
        if (! $wrote) {
            return;
        }

        $this->failRun($run, $record, $e);
    }

    /**
     * The run's graph, or null if this build cannot build it.
     *
     * Every caller of this is a recovery path, and a recovery path that throws
     * is not one. The ordinary paths keep calling the registry directly: a
     * graph that will not build while a run is *starting* is a programming
     * error and should be loud where the programmer is standing.
     */
    private function graphOrNull(PipelineRun $run): ?StepGraph
    {
        try {
            return $this->registry->graph($run->pipeline);
        } catch (InvalidPipelineDefinition $e) {
            Log::error('A live run belongs to a pipeline this build cannot describe', [
                'run_id' => $run->getKey(),
                'pipeline' => $run->pipeline,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resumeUnderTenant(PipelineRun $run): void
    {
        $graph = $this->graphOrNull($run);

        // Nothing this build can do will move it. Resuming is the one path that
        // must reach that conclusion out loud rather than by throwing: it is
        // called by `pipeline:reap` in a loop over every project, and a run
        // whose pipeline was renamed would otherwise abort the sweep before the
        // recoverable runs behind it were looked at.
        if ($graph === null) {
            $stuck = $run->steps()
                ->whereNotIn('status', [
                    PipelineStepStatus::Succeeded->value,
                    PipelineStepStatus::Skipped->value,
                ])
                ->first();

            if ($stuck !== null) {
                $this->strand($run, $stuck, new InvalidPipelineDefinition(
                    "Pipeline `{$run->pipeline}` cannot be built by this release, so the run cannot continue."
                ));
            }

            return;
        }

        foreach ($run->steps()->get() as $record) {
            if (! $record->isStale($graph->step($record->step_key)->timeout())) {
                continue;
            }

            Log::warning('Releasing a pipeline step left running by a worker that never came back', [
                'run_id' => $run->getKey(),
                'step' => $record->step_key,
                'attempt' => $record->attempt,
            ]);

            $this->writeClaimed($record, $record->attempt, [
                'status' => PipelineStepStatus::Pending->value,
                'started_at' => null,
            ]);
        }

        if ($run->status->isTerminal()) {
            $run->forceFill([
                'status' => PipelineRunStatus::Running,
                'error' => null,
                'failed_step_key' => null,
                'finished_at' => null,
            ])->save();
        }

        $this->dispatchReadyUnderTenant($run->refresh());
    }

    /**
     * Do the work as the project the run belongs to.
     *
     * Every table this touches is tenant-scoped and the scope fails closed, so
     * without this a runner called from anywhere that has no current project —
     * a console command, a scheduled job, the moment straight after `start()`
     * returns — reads zero steps, dispatches nothing and reports success. It
     * was exactly that: `pipeline:run` created a run with four step rows, found
     * none of them, and left it sitting at `pending` forever.
     *
     * By id rather than by `$run->project`, which would be a lazy load the
     * application is configured to refuse.
     */
    private function forRun(PipelineRun $run, callable $work): void
    {
        $this->currentProject->run($run->project_id, static function () use ($work): void {
            // A void closure on purpose: `run()` restores the previous tenant
            // before a returned PendingDispatch builds its payload, so a job
            // handed out through here would queue under the wrong project. See
            // the note in the README.
            $work();
        });
    }

    // ---------------------------------------------------------------- claim

    /**
     * Take ownership of a step for one attempt, or return null.
     *
     * The `where attempt = $record->attempt` is the whole guard: two deliveries
     * read the same row, both try to move it from N to N+1, and exactly one
     * update reports an affected row.
     *
     * A step still `running` past its timeout plus the grace period is taken
     * over rather than skipped — that is how a run whose worker was killed gets
     * moving again without a human. The takeover is logged, because a step that
     * quietly ran twice otherwise looks exactly like one that ran once.
     */
    private function claim(StepRecord $record, Step $step): ?int
    {
        $attempt = $record->attempt + 1;
        $abandonedAfter = now()->subSeconds($step->timeout() + (int) config('pipeline.stale_claim_grace', 60));

        $tookOver = $record->status === PipelineStepStatus::Running
            && $record->started_at !== null
            && $record->started_at->lessThan($abandonedAfter);

        $claimed = StepRecord::query()
            ->whereKey($record->getKey())
            ->where('attempt', $record->attempt)
            ->where(function ($query) use ($abandonedAfter): void {
                $query->whereIn('status', [
                    PipelineStepStatus::Pending->value,
                    PipelineStepStatus::Failed->value,
                ])->orWhere(fn ($stranded) => $stranded
                    ->where('status', PipelineStepStatus::Running->value)
                    ->where('started_at', '<', $abandonedAfter));
            })
            ->update([
                'status' => PipelineStepStatus::Running->value,
                'attempt' => $attempt,
                'started_at' => now(),
                'finished_at' => null,
            ]);

        if ($claimed !== 1) {
            return null;
        }

        if ($tookOver) {
            Log::warning('Took over a pipeline step left running by a worker that never came back', [
                'run_id' => $record->pipeline_run_id,
                'step' => $record->step_key,
                'abandoned_attempt' => $record->attempt,
            ]);
        }

        $record->refresh();

        return $attempt;
    }

    /**
     * Write to a step row only while `$claimed` still owns it.
     *
     * The claim proves ownership once; this keeps it true for the rest of the
     * attempt. A worker wedged past its timeout has its step taken over by the
     * next delivery, and without this guard its eventual write would land on
     * top of the attempt that replaced it — reporting one worker's result as
     * another's.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeClaimed(StepRecord $record, int $claimed, array $values): bool
    {
        $changed = StepRecord::query()
            ->whereKey($record->getKey())
            ->where('attempt', $claimed)
            // The query builder does not apply the model's casts, so arrays
            // have to be encoded here.
            ->update(array_map(
                static fn (mixed $value): mixed => is_array($value) ? json_encode($value) : $value,
                $values,
            ));

        if ($changed !== 1) {
            Log::warning('A pipeline step attempt finished without still holding its claim', [
                'run_id' => $record->pipeline_run_id,
                'step' => $record->step_key,
                'claimed_attempt' => $claimed,
            ]);

            return false;
        }

        $record->refresh();

        return true;
    }

    // ------------------------------------------------------------- outcomes

    private function succeed(
        PipelineRun $run,
        StepRecord $record,
        int $claimed,
        StepContext $context,
        StepResult $result,
    ): void {
        $usage = $this->meter($context, $run->price_list_version);

        $wrote = $this->writeClaimed($record, $claimed, [
            'status' => $result->status->value,
            'finished_at' => now(),
            'latency_ms' => $this->elapsedMs($record),
            'output' => $result->outputArray(),
            'error' => null,
            ...$usage,
        ]);

        // Not ours any more: another attempt took the step over while this one
        // was working. Reporting its result — or dispatching what comes next on
        // the strength of it — would be acting on somebody else's run.
        if (! $wrote) {
            return;
        }

        if ($context->remembered() !== []) {
            $this->rememberOnRun($run, $context->remembered());
        }

        $this->dispatchReady($run->refresh());
    }

    private function fail(
        PipelineRun $run,
        StepRecord $record,
        int $claimed,
        Step $step,
        ?StepContext $context,
        Throwable $e,
    ): ?PendingStepRetry {
        $retryable = $this->errors->isRetryable($e);
        $willRetry = $retryable && $claimed < $step->retries();

        // Tokens spent before the failure are still spent, so they are still
        // recorded (§6 — the cost of a run includes what its failures cost).
        $usage = $context === null ? [] : $this->meter($context, $run->price_list_version);

        $wrote = $this->writeClaimed($record, $claimed, [
            'status' => PipelineStepStatus::Failed->value,
            'finished_at' => now(),
            'latency_ms' => $this->elapsedMs($record),
            'error' => $this->errors->describe($e),
            ...$usage,
        ]);

        if (! $wrote) {
            return null;
        }

        Log::warning('Pipeline step failed', [
            'run_id' => $run->getKey(),
            'step' => $step::key(),
            'attempt' => $claimed,
            'will_retry' => $willRetry,
            'retryable' => $retryable,
            'exception' => $e->getMessage(),
        ]);

        if (! $willRetry) {
            $this->failRun($run, $record, $e);

            return null;
        }

        return new PendingStepRetry(
            runId: $run->getKey(),
            stepKey: $step::key(),
            queue: $step->queue(),
            delaySeconds: $this->backoffFor($step, $claimed),
        );
    }

    /** Backoff for the attempt that just failed; the last value repeats. */
    private function backoffFor(Step $step, int $attempt): int
    {
        $schedule = $step->backoff();

        if ($schedule === []) {
            return 0;
        }

        return $schedule[min($attempt - 1, count($schedule) - 1)];
    }

    private function failRun(PipelineRun $run, StepRecord $record, Throwable $e): void
    {
        $run->forceFill([
            'status' => PipelineRunStatus::Failed,
            'failed_step_key' => $record->step_key,
            'error' => $this->errors->describe($e),
            'finished_at' => now(),
        ])->save();

        $run->rollUpTotals();

        PipelineRunFinished::dispatch($run);
    }

    /**
     * Finish the run — once.
     *
     * Compare-and-set, because parallel branches settle at the same instant and
     * every one of them then asks "is there anything left?". Without the guard
     * the last two would both complete the run and both fire whatever comes to
     * hang off that.
     */
    private function complete(PipelineRun $run): void
    {
        $completed = PipelineRun::query()
            ->whereKey($run->getKey())
            ->whereIn('status', [PipelineRunStatus::Pending->value, PipelineRunStatus::Running->value])
            ->update([
                'status' => PipelineRunStatus::Completed->value,
                'finished_at' => now(),
            ]);

        if ($completed !== 1) {
            return;
        }

        $run->refresh()->rollUpTotals();

        PipelineRunFinished::dispatch($run);
    }

    private function markRunStarted(PipelineRun $run): void
    {
        if ($run->status !== PipelineRunStatus::Pending) {
            return;
        }

        PipelineRun::query()
            ->whereKey($run->getKey())
            ->where('status', PipelineRunStatus::Pending->value)
            ->update([
                'status' => PipelineRunStatus::Running->value,
                'started_at' => now(),
            ]);

        $run->refresh();
    }

    // -------------------------------------------------------------- helpers

    /**
     * Fold a step's model calls into columns (§3.4).
     *
     * Cost is computed against the run's own price list version, not today's,
     * so a run started before a price change finishes priced the way it began.
     *
     * @return array<string, mixed>
     */
    private function meter(StepContext $context, int $priceListVersion): array
    {
        $usage = $context->usage();

        if ($usage === []) {
            return [];
        }

        $input = 0;
        $output = 0;
        $cost = 0;

        foreach ($usage as $response) {
            $input += $response->inputTokens;
            $output += $response->outputTokens;
            $cost += $this->catalog->cost(
                $response->model,
                $response->inputTokens,
                $response->outputTokens,
                $priceListVersion,
            );
        }

        $last = $usage[array_key_last($usage)];

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_micros' => $cost,
            // The last call's model, which for a step that makes several is the
            // one that did the work the step is named after.
            'provider' => $last->provider,
            'model' => $last->model,
            'role' => $last->role,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function rememberOnRun(PipelineRun $run, array $values): void
    {
        // Read-modify-write on a json column: parallel steps can both be
        // writing, so it happens under a row lock rather than optimistically.
        DB::transaction(function () use ($run, $values): void {
            /** @var PipelineRun $fresh */
            $fresh = PipelineRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            $fresh->forceFill(['context' => [...$fresh->context, ...$values]])->save();
        });
    }

    /**
     * Every ancestor's output, not just the direct parents'.
     *
     * See StepGraph::ancestorsOf() — the alternative is steps declaring edges
     * they do not need in order to read a payload they do.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function dependencyOutputs(PipelineRun $run, StepGraph $graph, string $stepKey): array
    {
        $keys = $graph->ancestorsOf($stepKey);

        if ($keys === []) {
            return [];
        }

        return $run->steps()
            ->whereIn('step_key', $keys)
            ->get()
            ->mapWithKeys(fn (StepRecord $r): array => [$r->step_key => $r->output])
            ->all();
    }

    /** @return list<string> */
    private function settledKeys(PipelineRun $run): array
    {
        /** @var list<string> $keys */
        $keys = $run->steps()
            ->whereIn('status', [
                PipelineStepStatus::Succeeded->value,
                PipelineStepStatus::Skipped->value,
            ])
            ->get()
            ->map(fn (StepRecord $r): string => $r->step_key)
            ->values()
            ->all();

        return $keys;
    }

    /**
     * Read the run's status from the database rather than from the model in
     * hand: the copy a worker holds was loaded before the job waited in a
     * queue, and "has this been stopped?" is a question about now. Both callers
     * are guarding paid work, so a primary-key lookup is the cheap part.
     */
    private function stoppedStatus(string $runId): ?PipelineRunStatus
    {
        $status = PipelineRun::query()->whereKey($runId)->value('status');

        if ($status instanceof PipelineRunStatus) {
            return $status;
        }

        return $status === null ? null : PipelineRunStatus::tryFrom((string) $status);
    }

    private function elapsedMs(StepRecord $record): int
    {
        if ($record->started_at === null) {
            return 0;
        }

        return max(0, (int) $record->started_at->diffInMilliseconds(now()));
    }
}
