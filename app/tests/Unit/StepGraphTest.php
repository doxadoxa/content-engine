<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Pipelines\Core\PipelineRegistry;
use App\Pipelines\Core\StepGraph;
use App\Pipelines\Exceptions\InvalidPipelineDefinition;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Pipelines\CycleOneStep;
use Tests\Support\Pipelines\CycleTwoStep;
use Tests\Support\Pipelines\DanglingStep;
use Tests\Support\Pipelines\FixturePipeline;
use Tests\Support\Pipelines\JoinStep;
use Tests\Support\Pipelines\LeftStep;
use Tests\Support\Pipelines\RightStep;
use Tests\Support\Pipelines\RootStep;
use Tests\TestCase;

/**
 * The scheduler's arithmetic, without a database or a queue.
 *
 * A broken graph is the failure that costs the most to diagnose anywhere else:
 * a cycle shows up as a run that simply never finishes, and a dangling
 * dependency as a step that waits forever for something nobody will produce.
 * Both are caught here, before a run exists.
 */
final class StepGraphTest extends TestCase
{
    #[Test]
    public function it_sorts_a_diamond_into_a_workable_order(): void
    {
        $order = $this->diamond()->order();

        $this->assertSame('root', $order[0]);
        $this->assertSame('join', $order[3]);
        $this->assertEqualsCanonicalizing(['left', 'right'], [$order[1], $order[2]]);
    }

    #[Test]
    public function the_order_is_the_same_every_time(): void
    {
        // Position is stored on the row and read by humans; it must not depend
        // on hash iteration order or on the order of the definition's list.
        $this->assertSame($this->diamond()->order(), $this->diamond()->order());

        $reversed = StepGraph::for(FixturePipeline::of([
            LeftStep::class,
            RootStep::class,
            JoinStep::class,
            RightStep::class,
        ]));

        $this->assertSame($this->diamond()->order(), $reversed->order());
    }

    #[Test]
    public function only_the_root_is_ready_at_the_start(): void
    {
        $graph = $this->diamond();

        $this->assertSame(['root'], $graph->ready(['root', 'left', 'right', 'join'], []));
    }

    #[Test]
    public function both_branches_become_ready_together(): void
    {
        $graph = $this->diamond();

        // This single call is the whole of the parallelism: two steps come back
        // at once and the runner dispatches both.
        $this->assertEqualsCanonicalizing(
            ['left', 'right'],
            $graph->ready(['left', 'right', 'join'], ['root']),
        );
    }

    #[Test]
    public function the_fan_in_waits_for_every_branch(): void
    {
        $graph = $this->diamond();

        $this->assertSame([], $graph->ready(['join'], ['root', 'left']));
        $this->assertSame(['join'], $graph->ready(['join'], ['root', 'left', 'right']));
    }

    #[Test]
    public function a_skipped_dependency_still_releases_what_follows(): void
    {
        // `ready()` is given settled keys, and skipped counts as settled — a
        // step that was unnecessary must not strand the rest of the pipeline.
        $this->assertSame(['join'], $this->diamond()->ready(['join'], ['root', 'left', 'right']));
    }

    #[Test]
    public function a_cycle_is_refused_with_the_steps_that_form_it(): void
    {
        $this->expectException(InvalidPipelineDefinition::class);
        $this->expectExceptionMessageMatches('/cycle.*cycle_one.*cycle_two/s');

        StepGraph::for(FixturePipeline::of([CycleOneStep::class, CycleTwoStep::class]));
    }

    #[Test]
    public function a_dependency_on_a_step_that_is_not_there_is_refused(): void
    {
        $this->expectException(InvalidPipelineDefinition::class);
        $this->expectExceptionMessageMatches('/`nobody`/');

        StepGraph::for(FixturePipeline::of([RootStep::class, DanglingStep::class]));
    }

    #[Test]
    public function a_pipeline_with_no_steps_is_refused(): void
    {
        $this->expectException(InvalidPipelineDefinition::class);

        StepGraph::for(FixturePipeline::of([]));
    }

    #[Test]
    public function asking_for_a_step_it_does_not_have_is_refused(): void
    {
        $this->expectException(InvalidPipelineDefinition::class);

        $this->diamond()->step('nowhere');
    }

    #[Test]
    public function the_real_demo_pipeline_is_a_valid_graph(): void
    {
        // Cheap insurance: every registered pipeline is built at least once by
        // the suite, so a dependency typo in a definition cannot ship.
        foreach (app(PipelineRegistry::class)->all() as $key => $definition) {
            $graph = StepGraph::for($definition);

            $this->assertNotSame([], $graph->order(), "Pipeline `{$key}` has no steps.");
        }
    }

    private function diamond(): StepGraph
    {
        return StepGraph::for(FixturePipeline::of([
            // Deliberately not in dependency order: order is derived, and a
            // definition that happens to be sorted would hide it if it weren't.
            JoinStep::class,
            RightStep::class,
            RootStep::class,
            LeftStep::class,
        ]));
    }
}
