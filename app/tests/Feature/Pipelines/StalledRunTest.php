<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Jobs\RunStepJob;
use App\Pipelines\Steps\Demo\CountWords;
use App\Pipelines\Steps\Demo\ReadBrief;
use App\Pipelines\Steps\Demo\SummariseTopic;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The run nobody is working on and nobody will.
 *
 * Reconstructed from an incident. On 2026-08-07 a `visibility` run finished its
 * second step and dispatched its third to a worker whose config predated the
 * pipeline. The step threw `No pipeline `visibility` is registered`; the queue
 * called `failed()`; and the failure handler threw the *identical* exception
 * before writing anything, because it too began by asking the registry for the
 * graph. Nothing wrote a terminal status, so:
 *
 * - the run sat at `running` with a `pending` step for two days,
 * - the dashboard drew "Live · 2 of 3" that no reload could clear,
 * - and `EngineTickCommand::isBusy()` — which waits for any live run in the
 *   article contour — drafted nothing for that project the whole time.
 *
 * Three defences, one per direction the failure travelled: the handler survives
 * a graph it cannot build, `pipeline:reap` picks up what nothing delivered, and
 * neither the tick nor the dashboard counts hours-old wreckage as work.
 */
final class StalledRunTest extends TestCase
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

    // -------------------------------------------- the handler that threw too

    #[Test]
    public function a_step_of_a_pipeline_this_build_cannot_build_fails_its_run(): void
    {
        Queue::fake();

        $run = $this->start();

        // The exact shape of the incident: the run row outlives the release
        // that knew what its pipeline was. Reached in production through a
        // worker holding a stale config; reached here by taking the definition
        // away, which is the same thing from the runner's point of view.
        $this->forgetThePipeline();

        // What the queue does when a job dies. It used to throw straight back
        // out of here, leaving every row exactly as it found them.
        (new RunStepJob($run->getKey(), ReadBrief::key()))
            ->failed(new RuntimeException('No pipeline `demo` is registered.'));

        $run->refresh();

        $this->assertSame(PipelineRunStatus::Failed, $run->status, 'The run must not be left running.');
        $this->assertSame(ReadBrief::key(), $run->failed_step_key);

        $this->assertSame(
            PipelineStepStatus::Failed,
            $run->steps()->where('step_key', ReadBrief::key())->firstOrFail()->status,
        );
    }

    #[Test]
    public function resuming_a_run_whose_pipeline_is_gone_fails_it_instead_of_throwing(): void
    {
        Queue::fake();

        $run = $this->start();

        $this->forgetThePipeline();

        // `pipeline:reap` sweeps every project in a loop. One unbuildable run
        // throwing here would abort the sweep before the recoverable runs
        // behind it were even looked at.
        app(PipelineRunner::class)->resume($run);

        $this->assertSame(PipelineRunStatus::Failed, $run->refresh()->status);
    }

    // ------------------------------------------------------- the missing sweep

    #[Test]
    public function a_run_whose_next_step_was_never_delivered_is_picked_back_up(): void
    {
        $run = $this->wedged();

        // Precisely the state the incident left behind: two steps done, the
        // third `pending` at attempt 0, and no job anywhere that would ever
        // deliver it.
        $this->assertSame(
            PipelineStepStatus::Pending,
            $run->steps()->where('step_key', CountWords::key())->firstOrFail()->status,
        );

        $queue = Queue::fake();

        $this->reap();

        $queue->assertPushed(
            fn (RunStepJob $job): bool => $job->runId === $run->getKey()
                && $job->stepKey === CountWords::key(),
        );
    }

    #[Test]
    public function the_sweep_leaves_a_run_that_is_merely_young_alone(): void
    {
        $queue = Queue::fake();

        $run = $this->start();

        // Starting the run dispatched its root step, so the question is not
        // whether anything was pushed but whether the sweep pushed anything.
        $before = count($queue->pushed(RunStepJob::class));

        $this->reap();

        // A step sitting in a queue behind other work has not stalled, and
        // re-dispatching every young run every ten minutes would double the
        // engine's queue traffic to no end.
        $this->assertCount($before, $queue->pushed(RunStepJob::class));
        $this->assertFalse(PipelineRun::query()->stalled()->whereKey($run->getKey())->exists());
    }

    #[Test]
    public function a_swept_run_finishes(): void
    {
        $run = $this->wedged();

        // The end of it: not "a job was queued" but the run actually reaching
        // `completed` — which is what the operator was waiting two days for.
        $this->reap();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
    }

    // ------------------------------------------ what the rest of the app sees

    #[Test]
    public function wreckage_stops_counting_as_work_in_progress(): void
    {
        $run = $this->wedged();

        // Stalled but recent: still in flight, because the reaper has barely
        // had a pass at it and the tick must not start a second copy of work
        // that is one dispatch away from finishing.
        $this->assertTrue(PipelineRun::query()->stalled()->whereKey($run->getKey())->exists());
        $this->assertTrue(PipelineRun::query()->inFlight()->whereKey($run->getKey())->exists());

        // Hours later, with the reaper having failed to move it every ten
        // minutes throughout, it is not work any more. This is the bound whose
        // absence cost two days of drafting.
        Carbon::setTestNow(now()->addSeconds((int) config('pipeline.abandon_after') + 60));

        $this->assertFalse(PipelineRun::query()->inFlight()->whereKey($run->getKey())->exists());
    }

    /**
     * A run left exactly as the incident left one: everything up to a point
     * settled, the next step `pending`, and nothing queued to deliver it.
     */
    private function wedged(): PipelineRun
    {
        $real = Queue::getFacadeRoot();

        Queue::fake();

        $run = $this->start();

        app(PipelineRunner::class)->execute($run, ReadBrief::key());
        app(PipelineRunner::class)->execute($run, SummariseTopic::key());

        // The dispatch that went missing. Older than `stall_after`, which is
        // the only thing that distinguishes a lost message from a slow queue.
        Carbon::setTestNow(now()->addSeconds((int) config('pipeline.stall_after') + 60));

        // Back to a queue that runs things, so what follows is the recovery
        // rather than another assertion about a fake.
        Queue::swap($real);

        return $run->refresh();
    }

    private function reap(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('pipeline:reap');

        $command->assertSuccessful()->run();
    }

    private function start(): PipelineRun
    {
        return app(PipelineRunner::class)->start('demo', $this->project, ['topic' => 'window cleaning']);
    }

    /**
     * Leave the run row behind and take its pipeline away.
     *
     * A release that renames or drops a pipeline does this to every run of it
     * still in flight, and so does a worker that has not been restarted since
     * one was added — from the runner's side the two are the same event.
     */
    private function forgetThePipeline(): void
    {
        config()->set('pipeline.pipelines', []);
    }
}
