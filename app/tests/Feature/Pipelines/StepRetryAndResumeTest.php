<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Jobs\RunStepJob;
use App\Pipelines\Steps\Demo\AssembleResult;
use App\Pipelines\Steps\Demo\CountWords;
use App\Pipelines\Steps\Demo\ReadBrief;
use App\Pipelines\Steps\Demo\SummariseTopic;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Exit criteria 2 and 3 of phase 3.
 *
 * 2. A worker killed mid-run: after a restart the run reaches the end and no
 *    step has run twice.
 * 3. A retryable failure retries with backoff; a terminal one fails the run at
 *    once.
 *
 * Failures are induced through the pipeline's own `fail_at` input rather than
 * by stubbing the runner, so what is under test is what happens when a real
 * step throws.
 */
final class StepRetryAndResumeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        config()->set('queue.default', 'sync');
    }

    // ------------------------------------------------------------- retrying

    #[Test]
    public function a_retryable_failure_is_retried_with_backoff(): void
    {
        Queue::fake();

        $run = $this->start(['fail_at' => ReadBrief::key(), 'fail_with' => 'retryable']);

        // The root was dispatched; run it and let it throw.
        app(PipelineRunner::class)->execute($run, ReadBrief::key());

        $step = $run->steps()->where('step_key', ReadBrief::key())->firstOrFail();

        $this->assertSame(PipelineStepStatus::Failed, $step->status);
        $this->assertSame(1, $step->attempt);
        $this->assertTrue($step->error['retryable']);

        // The run is still alive: a retryable failure with attempts left is not
        // the run's failure.
        $this->assertNotSame(PipelineRunStatus::Failed, $run->refresh()->status);

        // Re-queued, and delayed — the first entry of the backoff schedule.
        Queue::assertPushed(function (RunStepJob $job): bool {
            return $job->stepKey === ReadBrief::key() && $job->delay !== null;
        });
    }

    #[Test]
    public function retrying_stops_at_the_attempt_limit_and_fails_the_run(): void
    {
        $run = $this->start(['fail_at' => ReadBrief::key(), 'fail_with' => 'retryable']);

        $runner = app(PipelineRunner::class);
        $retries = app(ReadBrief::class)->retries();

        // The sync driver runs a delayed job immediately, so `start()` has
        // already burned the attempts. Drive any remainder by hand.
        for ($i = 0; $i < $retries + 1; $i++) {
            $runner->execute($run->refresh(), ReadBrief::key());
        }

        $step = $run->steps()->where('step_key', ReadBrief::key())->firstOrFail();

        $this->assertSame(PipelineStepStatus::Failed, $step->status);
        $this->assertSame($retries, $step->attempt, 'The step was attempted more times than it allows.');

        $run->refresh();
        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame(ReadBrief::key(), $run->failed_step_key);
        $this->assertNotNull($run->error);
    }

    #[Test]
    public function a_terminal_failure_fails_the_run_on_the_first_attempt(): void
    {
        $run = $this->start(['fail_at' => ReadBrief::key(), 'fail_with' => 'terminal']);

        $step = $run->steps()->where('step_key', ReadBrief::key())->firstOrFail();

        // One attempt, no backoff, no second chance: retrying a validation
        // error spends money to reach the same answer more slowly (§3.1).
        $this->assertSame(1, $step->attempt);
        $this->assertFalse($step->error['retryable']);

        $run->refresh();
        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame(ReadBrief::key(), $run->failed_step_key);
    }

    #[Test]
    public function a_failed_run_does_not_dispatch_anything_further(): void
    {
        $this->start(['fail_at' => ReadBrief::key(), 'fail_with' => 'terminal']);

        $run = PipelineRun::query()->firstOrFail();

        Queue::fake();
        app(PipelineRunner::class)->dispatchReady($run);

        Queue::assertNothingPushed();

        // …and the steps behind the failure were never started.
        $this->assertSame(
            PipelineStepStatus::Pending,
            $run->steps()->where('step_key', AssembleResult::key())->firstOrFail()->status,
        );
    }

    #[Test]
    public function one_branch_failing_does_not_settle_the_fan_in(): void
    {
        $run = $this->start(['fail_at' => SummariseTopic::key(), 'fail_with' => 'terminal']);

        $run->refresh();

        // The sibling branch is independent and got its work done.
        $this->assertSame(
            PipelineStepStatus::Succeeded,
            $run->steps()->where('step_key', CountWords::key())->firstOrFail()->status,
        );

        // The fan-in depends on both, so it never became ready.
        $this->assertSame(
            PipelineStepStatus::Pending,
            $run->steps()->where('step_key', AssembleResult::key())->firstOrFail()->status,
        );

        $this->assertSame(PipelineRunStatus::Failed, $run->status);
    }

    // -------------------------------------------------------------- resuming

    #[Test]
    public function a_run_whose_worker_was_killed_finishes_after_a_restart(): void
    {
        // The null driver rather than Queue::fake(): both stop jobs from
        // running, but a fake cannot be undone, and this test needs the queue
        // to work again afterwards — that is the restart.
        config()->set('queue.default', 'null');

        $run = $this->start();

        // The root runs and succeeds. Then the worker holding the next step
        // disappears mid-flight, which is precisely a step left in `running`
        // with a claim nobody will ever release.
        app(PipelineRunner::class)->execute($run, ReadBrief::key());

        $run->steps()->where('step_key', CountWords::key())->update([
            'status' => PipelineStepStatus::Running->value,
            'attempt' => 1,
            'started_at' => now()->subHour(),
        ]);

        $this->assertSame(PipelineRunStatus::Running, $run->refresh()->status);
        $this->assertFalse($run->allStepsSettled());

        // Restart.
        config()->set('queue.default', 'sync');

        app(PipelineRunner::class)->resume($run->refresh());

        $run->refresh();

        $this->assertSame(PipelineRunStatus::Completed, $run->status);
        $this->assertTrue($run->allStepsSettled());

        // Nothing ran twice: the root kept its single attempt, and the step
        // that was taken over shows exactly the one retry it needed.
        $this->assertSame(1, $run->steps()->where('step_key', ReadBrief::key())->firstOrFail()->attempt);
        $this->assertLessThanOrEqual(
            2,
            $run->steps()->where('step_key', CountWords::key())->firstOrFail()->attempt,
        );
    }

    #[Test]
    public function resuming_never_runs_a_finished_step_a_second_time(): void
    {
        Queue::fake();

        $run = $this->start();

        app(PipelineRunner::class)->execute($run, ReadBrief::key());

        $before = $run->steps()->where('step_key', ReadBrief::key())->firstOrFail();
        $this->assertSame(PipelineStepStatus::Succeeded, $before->status);
        $this->assertSame(1, $before->attempt);

        // Re-deliver the same step: a queue that redelivers, a resume, a human
        // running the command twice — all the same thing from here.
        app(PipelineRunner::class)->execute($run->refresh(), ReadBrief::key());
        app(PipelineRunner::class)->execute($run->refresh(), ReadBrief::key());

        $after = $run->steps()->where('step_key', ReadBrief::key())->firstOrFail();

        // The claim refused both, so the attempt counter never moved — which is
        // the observable form of "no step ran twice" (§3.1's idempotency by
        // (pipeline_run_id, step_key)).
        $this->assertSame(1, $after->attempt);
        $this->assertEquals($before->finished_at, $after->finished_at);
    }

    #[Test]
    public function a_step_still_within_its_timeout_is_not_taken_over(): void
    {
        Queue::fake();

        $run = $this->start();

        // Claimed a moment ago by a worker that is presumably still working.
        $run->steps()->where('step_key', ReadBrief::key())->update([
            'status' => PipelineStepStatus::Running->value,
            'attempt' => 1,
            'started_at' => now(),
        ]);

        app(PipelineRunner::class)->execute($run->refresh(), ReadBrief::key());

        // Untouched: taking this over would put two workers on one step.
        $step = $run->steps()->where('step_key', ReadBrief::key())->firstOrFail();
        $this->assertSame(1, $step->attempt);
        $this->assertSame(PipelineStepStatus::Running, $step->status);
    }

    #[Test]
    public function a_killed_job_records_the_failure_rather_than_wedging_the_run(): void
    {
        Queue::fake();

        $run = $this->start();

        $run->steps()->where('step_key', ReadBrief::key())->update([
            'status' => PipelineStepStatus::Running->value,
            'attempt' => 1,
            'started_at' => now(),
        ]);

        // What Laravel calls when the worker kills the job outright.
        (new RunStepJob($run->getKey(), ReadBrief::key()))
            ->failed(new RuntimeException('worker went away'));

        $step = $run->steps()->where('step_key', ReadBrief::key())->firstOrFail();

        $this->assertSame(PipelineStepStatus::Failed, $step->status);
        $this->assertNotNull($step->error);

        // An unclassified exception is not retried, so the run fails rather
        // than sitting in `running` forever with nothing queued.
        $this->assertSame(PipelineRunStatus::Failed, $run->refresh()->status);
    }

    /** @param array<string, mixed> $input */
    private function start(array $input = []): PipelineRun
    {
        return app(PipelineRunner::class)->start('demo', $this->project, [
            'topic' => 'how to clean windows',
            ...$input,
        ]);
    }
}
