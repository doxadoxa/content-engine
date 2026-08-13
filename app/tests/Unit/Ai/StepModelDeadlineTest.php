<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Exceptions\RetryableStepFailure;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bound on a *handler*, as opposed to the bound on one call.
 *
 * A per-call timeout is not enough on its own, and the gap is easy to miss.
 * Five steps in this engine catch a failed model call and carry on, on purpose:
 * `SelectTopics` would rather write one duplicate article than plan an empty
 * month. Against a provider that accepts connections and never answers, that
 * turns one bounded call into one bounded call *per item in the loop* — seven
 * of them at five minutes each reaches the expensive worker's 2100-second
 * limit, the process is killed, nothing is recorded, and the step is
 * re-delivered. Which is precisely the failure the per-call timeout was added
 * to remove.
 *
 * So the context refuses to start a call it cannot finish before the step's own
 * declared deadline — the number the rest of the engine already agrees on,
 * since past it another delivery may take the step over.
 */
final class StepModelDeadlineTest extends TestCase
{
    private FakeModelGateway $models;

    #[Test]
    public function a_call_that_fits_inside_the_deadline_goes_ahead(): void
    {
        $context = $this->context(deadlineIn: 600);

        $this->assertSame('answer', $context->ask('utility', 'anything')->text);
    }

    #[Test]
    public function a_call_that_would_outlive_the_deadline_is_refused_before_it_is_made(): void
    {
        config()->set('models.timeout', 300);

        // 120 seconds left, and a call may take 300. Starting it means a
        // handler still working while another delivery is entitled to take the
        // step over — two workers on one step, one of them writing results
        // nobody will read.
        $context = $this->context(deadlineIn: 120);

        $this->expectException(RetryableStepFailure::class);

        $context->ask('utility', 'anything');
    }

    #[Test]
    public function nothing_is_sent_to_the_provider_when_the_call_is_refused(): void
    {
        config()->set('models.timeout', 300);

        $context = $this->context(deadlineIn: 10);

        try {
            $context->ask('utility', 'anything');
        } catch (RetryableStepFailure) {
            // Expected.
        }

        // The point is not to fail politely but to stop paying: a refused call
        // must cost no wall clock and no tokens.
        $this->assertSame([], $this->models->sent());
        $this->assertSame([], $context->usage());
    }

    #[Test]
    public function the_refusal_is_retryable_so_the_step_gets_a_fresh_deadline(): void
    {
        config()->set('models.timeout', 300);

        $context = $this->context(deadlineIn: 5);

        try {
            $context->ask('utility', 'anything');

            $this->fail('The call should have been refused.');
        } catch (RetryableStepFailure $e) {
            $this->assertStringContainsString('no time left', $e->getMessage());
        }
    }

    #[Test]
    public function a_context_with_no_deadline_is_unbounded_as_before(): void
    {
        // Steps constructed by hand in tests, and anything else that builds a
        // context without a step behind it, must not start refusing calls.
        $context = $this->context(deadlineIn: null);

        $this->assertSame('answer', $context->ask('utility', 'anything')->text);
    }

    private function context(?int $deadlineIn): StepContext
    {
        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;
        $this->models->willAnswer(['answer', 'answer', 'answer']);

        return new StepContext(
            run: new PipelineRun,
            project: new Project,
            input: [],
            dependencyOutputs: [],
            runContext: [],
            gateway: $this->models,
            deadlineAt: $deadlineIn === null ? null : Carbon::now()->addSeconds($deadlineIn),
        );
    }
}
