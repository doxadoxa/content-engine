<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exit criterion 1 of phase 3: a four-step DAG with two parallel branches runs
 * through the queue and leaves a full trace.
 *
 * The runs here go through the real queue rather than through the runner's
 * internals. `Queue::fake()` would prove the jobs were dispatched and nothing
 * about whether the engine works; the sync driver actually executes them, which
 * is what makes fan-out and fan-in observable.
 */
final class DemoPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        /** @var FakeModelGateway $gateway */
        $gateway = app(ModelGateway::class);
        $this->models = $gateway;

        // Jobs run inline, so `start()` walks the whole DAG before returning.
        config()->set('queue.default', 'sync');
    }

    #[Test]
    public function the_demo_dag_runs_to_completion(): void
    {
        $run = $this->start();

        $run->refresh();

        $this->assertSame(PipelineRunStatus::Completed, $run->status);
        $this->assertNull($run->failed_step_key);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        $this->assertSame(4, $run->steps()->count());
        $this->assertTrue($run->allStepsSettled());
    }

    #[Test]
    public function every_step_records_a_full_trace(): void
    {
        $run = $this->start();

        foreach ($run->steps()->get() as $step) {
            $this->assertSame(PipelineStepStatus::Succeeded, $step->status, "{$step->step_key} did not succeed.");
            $this->assertSame(1, $step->attempt, "{$step->step_key} took more than one attempt.");
            $this->assertNotNull($step->started_at);
            $this->assertNotNull($step->finished_at);
            $this->assertNotNull($step->latency_ms);
            $this->assertNull($step->error);
            $this->assertIsArray($step->output);
        }
    }

    #[Test]
    public function the_graph_orders_the_steps_and_fans_out(): void
    {
        $run = $this->start();

        $positions = $run->steps()->get()
            ->mapWithKeys(fn ($step): array => [$step->step_key => $step->position])
            ->all();

        // The root first, the fan-in last, and the two branches between them —
        // in the same positions on every machine, because the sort is
        // deterministic.
        $this->assertSame(0, $positions[ReadBrief::key()]);
        $this->assertSame(3, $positions[AssembleResult::key()]);
        $this->assertContains($positions[CountWords::key()], [1, 2]);
        $this->assertContains($positions[SummariseTopic::key()], [1, 2]);
    }

    #[Test]
    public function the_two_branches_are_dispatched_together_onto_their_own_queues(): void
    {
        // Faked here on purpose: this is the one assertion about *dispatch*
        // rather than about execution, and it needs the run to stop after its
        // root step so the fan-out is visible as two jobs at once.
        Queue::fake();

        $run = $this->start();

        // Only the root is ready at the start.
        Queue::assertPushed(RunStepJob::class, 1);
        Queue::assertPushed(fn (RunStepJob $job): bool => $job->stepKey === ReadBrief::key());

        // Settle the root by hand and ask what is ready now.
        $run->steps()->where('step_key', ReadBrief::key())
            ->update(['status' => PipelineStepStatus::Succeeded->value, 'output' => json_encode([])]);

        app(PipelineRunner::class)->dispatchReady($run->refresh());

        // Both branches, at once, and each on the queue its step asked for: the
        // model call must not sit in the same pool as the cheap step (§3.2).
        Queue::assertPushed(fn (RunStepJob $job): bool => $job->stepKey === SummariseTopic::key()
            && $job->queue === config('pipeline.queues.expensive'));

        Queue::assertPushed(fn (RunStepJob $job): bool => $job->stepKey === CountWords::key()
            && $job->queue === config('pipeline.queues.cheap'));

        // The fan-in is not ready and must not have been queued.
        Queue::assertNotPushed(fn (RunStepJob $job): bool => $job->stepKey === AssembleResult::key());
    }

    #[Test]
    public function a_step_reads_its_dependencies_typed_output(): void
    {
        $this->models->willAnswer(['Windows, cleaned properly.']);

        $run = $this->start(['topic' => 'how to clean windows']);

        $assembled = $run->steps()->where('step_key', AssembleResult::key())->firstOrFail();

        // The fan-in saw both branches: the model's answer from one, the count
        // from the other.
        $this->assertSame('Windows, cleaned properly.', $assembled->output['headline']);
        $this->assertSame(4, $assembled->output['words']);
    }

    #[Test]
    public function a_step_can_leave_a_note_on_the_run(): void
    {
        $run = $this->start();

        $this->assertTrue($run->refresh()->context['demo.finished'] ?? false);
    }

    #[Test]
    public function a_run_started_with_no_current_project_still_executes(): void
    {
        // The console path — and the one every other test here hides by setting
        // a tenant in setUp(). Every table the runner touches is tenant-scoped
        // and the scope fails closed, so a runner that does not scope itself
        // reads zero steps, dispatches nothing, and reports success on a run
        // that then sits at `pending` forever.
        app(CurrentProject::class)->forget();

        $started = app(PipelineRunner::class)->start('demo', $this->project, [
            'topic' => 'how to clean windows',
        ]);

        $run = PipelineRun::acrossProjects()->whereKey($started->getKey())->firstOrFail();

        $this->assertSame(PipelineRunStatus::Completed, $run->status);
        $this->assertSame(4, PipelineStep::acrossProjects()->where('pipeline_run_id', $run->getKey())->count());
        $this->assertGreaterThan(0, $run->cost_micros);
    }

    #[Test]
    public function input_is_validated_before_anything_is_queued(): void
    {
        Queue::fake();

        try {
            $this->start(['topic' => '']);
            $this->fail('An empty topic should not start a run.');
        } catch (ValidationException) {
            // expected
        }

        // Nothing queued, and no half-built run left behind — a run that cannot
        // succeed should fail where the caller is still standing.
        Queue::assertNothingPushed();
        $this->assertSame(0, PipelineRun::query()->count());
    }

    /** @param array<string, mixed> $input */
    private function start(array $input = ['topic' => 'how to clean windows']): PipelineRun
    {
        return app(PipelineRunner::class)->start('demo', $this->project, $input);
    }
}
