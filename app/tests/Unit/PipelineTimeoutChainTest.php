<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Four numbers in four files that have to stay in order.
 *
 * A step's timeout, the model call's, the worker's, and the queue's
 * `retry_after` each belong to a different layer and each is edited by somebody
 * thinking about that layer alone. They are only correct in relation to each
 * other, and nothing in the four files makes that relation checkable — which is
 * why it had broken three times before this test covered it:
 *
 *   - `AskAssistants` asked for 1800 while the worker killed at 900, so the
 *     paid sweep its docblock exists to protect was being cut in half anyway;
 *   - `ApplyContentStudioAction` asked for exactly the worker's 900, a tie, so
 *     which one fired was a race;
 *   - `ApplyContentStudioAction` was then sized down to 300 against a
 *     `models.timeout` of 300, and a step with no room to start a single call
 *     is a step that fails every time it is asked to make one. It did, for
 *     five runs over six days, reported to the operator as a provider outage.
 *
 * **What each number does, since the failure is that they read alike.** A step
 * timeout is a deadline the pipeline enforces: it fails the step and records
 * the reason. A worker timeout is a signal: the process stops and no PHP runs
 * afterwards, so nothing is recorded and the step row sits in `running` until a
 * stale-claim sweep. `retry_after` is Redis deciding a reserved job was
 * abandoned and handing it to a second worker.
 *
 * So the order is forced:
 *
 *   step < worker — or the step's own deadline is unreachable and its failure
 *   is silent;
 *
 *   worker < retry_after — or Redis re-delivers a job that is still legitimately
 *   running, and two workers do the same work. `config/queue.php` spends a
 *   paragraph on what that means for a two-phase publish: a duplicated Threads
 *   job is a duplicated post.
 *
 * This reads the real config and the real step classes rather than restating
 * the numbers, so raising one and forgetting the others fails here.
 */
final class PipelineTimeoutChainTest extends TestCase
{
    #[Test]
    public function every_step_can_reach_its_own_deadline_before_the_worker_kills_it(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ($this->stepsByQueue() as $queue => $steps) {
            $worker = $this->workerTimeout($queue);

            if ($worker === null) {
                continue;
            }

            foreach ($steps as $class => $timeout) {
                $this->assertLessThan(
                    $worker,
                    $timeout,
                    "{$class} asks for {$timeout}s on the {$queue} queue, whose worker stops at "
                        ."{$worker}s. The worker always wins, so that step's timeout is a promise "
                        .'nothing keeps: raise the supervisor in config/horizon.php, or lower the step.',
                );
            }

            $this->assertLessThan(
                $retryAfter,
                $worker,
                "The {$queue} worker runs to {$worker}s and Redis re-delivers at {$retryAfter}s. "
                    .'Inverted, the queue hands a still-running job to a second worker — see the '
                    .'paragraph above retry_after in config/queue.php about duplicated publishes.',
            );
        }
    }

    /**
     * The expensive queue is the pool that exists for model calls, so every
     * step on it has to be able to start one. `StepContext` refuses a call it
     * cannot finish before the step's own deadline, which means the budget a
     * step needs is not "the work" but "the work plus a whole model timeout" —
     * and a step sized for the work alone does not fail slowly, it fails on the
     * first call in under a second.
     */
    #[Test]
    public function every_expensive_step_has_room_to_start_a_model_call(): void
    {
        $call = (int) config('models.timeout');
        $expensive = (string) config('pipeline.queues.expensive');

        foreach ($this->stepsByQueue()[$expensive] ?? [] as $class => $timeout) {
            $this->assertGreaterThan(
                $call,
                $timeout,
                "{$class} asks for {$timeout}s and one model call may take {$call}s "
                    .'(MODEL_TIMEOUT in config/models.php). StepContext refuses to start a call '
                    .'that could outlive the step, so this step would fail on its first one, in '
                    .'about as long as the refusal takes to throw.',
            );
        }
    }

    #[Test]
    public function the_expensive_queue_still_clears_its_longest_step(): void
    {
        // Named rather than derived, so that a step quietly growing its timeout
        // past the worker is a failure here with the number in the message,
        // rather than a silently truncated sweep in production.
        $steps = $this->stepsByQueue()[(string) config('pipeline.queues.expensive')] ?? [];

        $this->assertNotSame([], $steps, 'No step claims the expensive queue, which cannot be right.');
        $this->assertSame(
            1800,
            max([0, ...array_values($steps)]),
            'The longest expensive step changed; re-check the chain.',
        );
    }

    /**
     * Every step in every pipeline, grouped by the queue it runs on.
     *
     * @return array<string, array<class-string<Step>, int>>
     */
    private function stepsByQueue(): array
    {
        $byQueue = [];

        foreach ($this->definitions() as $definition) {
            foreach ($definition->steps() as $class) {
                /** @var Step $step */
                $step = app($class);
                $byQueue[$step->queue()][$class] = $step->timeout();
            }
        }

        return $byQueue;
    }

    /** @return list<PipelineDefinition> */
    private function definitions(): array
    {
        $found = [];

        foreach (glob(app_path('Pipelines/Definitions/*.php')) ?: [] as $file) {
            $class = 'App\\Pipelines\\Definitions\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(PipelineDefinition::class)) {
                continue;
            }

            $found[] = app($class);
        }

        return $found;
    }

    /** The supervisor that serves this queue, if one does. */
    private function workerTimeout(string $queue): ?int
    {
        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            if (in_array($queue, (array) ($supervisor['queue'] ?? []), true)) {
                return (int) $supervisor['timeout'];
            }
        }

        return null;
    }
}
