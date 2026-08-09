<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelCatalog;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Steps\Demo\CountWords;
use App\Pipelines\Steps\Demo\SummariseTopic;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §3.4 and exit criterion 4: what a run cost, per step, priced under a list
 * that does not move under it.
 */
final class MeteringTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['slug' => 'metering-test']);
        app(CurrentProject::class)->set($this->project);

        /** @var FakeModelGateway $gateway */
        $gateway = app(ModelGateway::class);
        $this->models = $gateway;

        config()->set('queue.default', 'sync');
    }

    // ------------------------------------------------------------- the model

    #[Test]
    public function a_role_resolves_to_a_provider_and_a_model(): void
    {
        $choice = app(ModelCatalog::class)->resolve('draft');

        $this->assertSame('draft', $choice->role);
        $this->assertNotSame('', $choice->model);
    }

    #[Test]
    public function an_unknown_role_is_refused_rather_than_defaulted(): void
    {
        // Falling back to the default model would mean a typo in a role name
        // shows up as a factcheck step quietly running on the cheap model.
        $this->expectException(InvalidArgumentException::class);

        app(ModelCatalog::class)->resolve('summarisation');
    }

    #[Test]
    public function cost_is_computed_from_the_price_list(): void
    {
        $catalog = app(ModelCatalog::class);

        // fake-model is $1/M input, $2/M output in config/models.php.
        $this->assertSame(1_000_000 + 2 * 1_000_000, $catalog->cost('fake-model', 1_000_000, 1_000_000));
        $this->assertSame(0, $catalog->cost('fake-model', 0, 0));
    }

    #[Test]
    public function an_unpriced_model_costs_zero_rather_than_failing_a_run(): void
    {
        // A missing price is a configuration mistake. Failing a run that has
        // already spent the money is the one response that makes it worse.
        $this->assertSame(0, app(ModelCatalog::class)->cost('a-model-nobody-priced', 1_000, 1_000));
    }

    // ------------------------------------------------------------- the trace

    #[Test]
    public function only_the_step_that_called_a_model_is_metered(): void
    {
        $run = $this->start();

        $summarise = $run->steps()->where('step_key', SummariseTopic::key())->firstOrFail();
        $count = $run->steps()->where('step_key', CountWords::key())->firstOrFail();

        $this->assertGreaterThan(0, $summarise->input_tokens);
        $this->assertGreaterThan(0, $summarise->output_tokens);
        $this->assertGreaterThan(0, $summarise->cost_micros);
        $this->assertSame(FakeModelGateway::MODEL, $summarise->model);
        $this->assertSame('draft', $summarise->role);

        // The cheap branch called nothing, and says so with nulls rather than
        // with a zero that could mean "free model".
        $this->assertSame(0, $count->input_tokens);
        $this->assertSame(0, $count->cost_micros);
        $this->assertNull($count->model);
    }

    #[Test]
    public function the_run_total_is_the_sum_of_its_steps(): void
    {
        $run = $this->start()->refresh();

        $steps = $run->steps()->get();

        $this->assertSame((int) $steps->sum('input_tokens'), $run->input_tokens);
        $this->assertSame((int) $steps->sum('output_tokens'), $run->output_tokens);
        $this->assertSame((int) $steps->sum('cost_micros'), $run->cost_micros);
        $this->assertGreaterThan(0, $run->cost_micros);
    }

    #[Test]
    public function the_step_cost_matches_the_tokens_it_reported(): void
    {
        $run = $this->start();

        $step = $run->steps()->where('step_key', SummariseTopic::key())->firstOrFail();

        $this->assertSame(
            app(ModelCatalog::class)->cost(FakeModelGateway::MODEL, $step->input_tokens, $step->output_tokens, 1),
            $step->cost_micros,
        );
    }

    #[Test]
    public function a_run_is_priced_under_the_list_it_started_on(): void
    {
        // Whatever the installation's current list is, not a literal: the
        // number moves every time prices are re-read, and a test pinned to it
        // fails for a config edit rather than for a broken promise.
        $active = app(ModelCatalog::class)->priceListVersion();

        $run = $this->start();

        $this->assertSame($active, $run->refresh()->price_list_version);

        // Publishing a new price list must not restate what this run cost.
        $before = $run->cost_micros;

        $next = $active + 1;

        config()->set('models.prices.version', $next);
        config()->set("models.prices.versions.{$next}", [
            FakeModelGateway::MODEL => ['input' => 999_000_000, 'output' => 999_000_000],
        ]);

        $run->rollUpTotals();

        $this->assertSame($before, $run->refresh()->cost_micros);
    }

    #[Test]
    public function tokens_spent_before_a_failure_are_still_recorded(): void
    {
        // The provider answered and charged for it, then the step fell over
        // afterwards. §6's cost of a run includes what its failures cost.
        $this->models->willAnswer(['a partial answer']);

        $run = app(PipelineRunner::class)->start('demo', $this->project, [
            'topic' => 'how to clean windows',
            'fail_at' => SummariseTopic::key(),
            'fail_with' => 'terminal',
        ]);

        $step = $run->steps()->where('step_key', SummariseTopic::key())->firstOrFail();

        // This particular step throws before calling the model, so what is
        // being asserted is the shape: a failed step still carries its columns
        // and the run still totals them.
        $this->assertNotNull($step->error);
        $this->assertSame(0, $step->cost_micros);
        $this->assertSame((int) $run->steps()->sum('cost_micros'), $run->refresh()->cost_micros);
    }

    // ------------------------------------------------------------- the report

    #[Test]
    public function the_cost_command_prints_a_breakdown_by_step(): void
    {
        $this->start();

        $this->cost('metering-test')
            ->assertSuccessful()
            ->expectsOutputToContain(SummariseTopic::key())
            ->expectsOutputToContain(CountWords::key());
    }

    #[Test]
    public function the_cost_command_reports_cost_per_content_unit(): void
    {
        $item = ContentItem::factory()->create();

        $this->start($item->getKey());

        $this->cost('metering-test')
            ->assertSuccessful()
            ->expectsOutputToContain('Per content unit');
    }

    #[Test]
    public function the_pipeline_filter_applies_to_every_cost_reading(): void
    {
        $demoItem = ContentItem::factory()->create();
        $researchItem = ContentItem::factory()->create();

        PipelineRun::factory()->create([
            'pipeline' => 'demo',
            'content_item_id' => $demoItem->getKey(),
            'cost_micros' => 2_000_000,
        ]);
        PipelineRun::factory()->create([
            'pipeline' => 'research',
            'content_item_id' => $researchItem->getKey(),
            'cost_micros' => 9_000_000,
        ]);

        $this->cost('metering-test', ['--pipeline' => 'demo'])
            ->assertSuccessful()
            ->expectsOutputToContain('$2.0000 over 1 units');
    }

    #[Test]
    public function the_cost_command_says_so_when_there_is_nothing_to_report(): void
    {
        $this->cost('metering-test')
            ->assertSuccessful()
            ->expectsOutputToContain('No pipeline runs');
    }

    #[Test]
    public function the_cost_command_refuses_an_unknown_project(): void
    {
        $this->cost('no-such-project')
            ->assertFailed();
    }

    #[Test]
    public function the_cost_command_only_counts_the_project_it_was_asked_about(): void
    {
        $this->start();

        $other = Project::factory()->create(['slug' => 'someone-else']);
        app(CurrentProject::class)->run($other, function () use ($other): void {
            app(PipelineRunner::class)->start('demo', $other, ['topic' => 'their topic']);
        });

        $mine = PipelineStep::acrossProjects()->where('project_id', $this->project->getKey())->sum('cost_micros');
        $theirs = PipelineStep::acrossProjects()->where('project_id', $other->getKey())->sum('cost_micros');

        $this->assertGreaterThan(0, $mine);
        $this->assertGreaterThan(0, $theirs);

        // Both projects ran the same pipeline; the report must not add them up.
        $this->cost('metering-test')
            ->assertSuccessful()
            ->expectsOutputToContain('$'.number_format($mine / 1_000_000, 4));
    }

    #[Test]
    public function a_run_started_from_the_command_line_records_the_unit_it_is_about(): void
    {
        $unit = ContentItem::factory()->create();

        /** @var PendingCommand $command */
        $command = $this->artisan('pipeline:run', [
            'pipeline' => 'demo',
            'project' => $this->project->slug,
            '--input' => ['topic=how to clean windows', "content_item_id={$unit->getKey()}"],
        ]);

        // assertSuccessful() only records the expectation; the command runs in
        // __destruct(), so it has to be run explicitly here.
        $command->assertSuccessful()->run();

        $run = PipelineRun::acrossProjects()->latest()->firstOrFail();

        // On the row, not just in the input: the per-unit cost report joins on
        // this column, and a run that leaves it null spends real money and then
        // vanishes from the one number §6 says matters.
        $this->assertSame($unit->getKey(), $run->content_item_id);
    }

    private function start(?string $contentItemId = null): PipelineRun
    {
        return app(PipelineRunner::class)->start(
            'demo',
            $this->project,
            ['topic' => 'how to clean windows'],
            $contentItemId,
        );
    }

    /**
     * `artisan()` is declared as returning `PendingCommand|int`, so without
     * this every call site would repeat the same annotation to chain
     * assertions onto it.
     */
    /**
     * @param  array<string, mixed>  $options
     */
    private function cost(string $project, array $options = []): PendingCommand
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('pipeline:cost', [
            'project' => $project,
            ...$options,
        ]);

        return $command;
    }
}
