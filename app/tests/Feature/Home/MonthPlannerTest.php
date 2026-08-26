<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Ai\Assistant\MarketingTools;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\User;
use App\Support\Engine\MonthPlanner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Asking for a month of articles, once.
 *
 * Planning reads the whole keyword set and writes a month of units, so two of
 * them racing plans the same month twice — twice the model spend, and every
 * topic on the calendar twice. The button and the assistant's `plan_month` tool
 * are two callers, and each used to do its own unguarded `exists()` first.
 */
final class MonthPlannerTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->operator = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->users()->attach($this->operator);

        $this->actingAs($this->operator);
        $this->withSession(['project_id' => $this->project->getKey()]);

        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function it_starts_one_month(): void
    {
        $run = app(MonthPlanner::class)->start($this->project);

        $this->assertNotNull($run);
        $this->assertSame('planning', $run->pipeline);
    }

    #[Test]
    public function a_second_ask_while_one_is_in_flight_gets_nothing(): void
    {
        $this->assertNotNull(app(MonthPlanner::class)->start($this->project));

        // Null rather than an exception: "already being planned" is a normal
        // answer, and both callers have somewhere sensible to put it.
        $this->assertNull(app(MonthPlanner::class)->start($this->project));
        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'planning')->count());
    }

    #[Test]
    public function the_check_and_the_start_happen_under_one_lock(): void
    {
        // Held by somebody else, so a caller that checked first and started
        // afterwards would sail past. The lock is the only thing that makes the
        // two steps one decision.
        $held = Cache::lock("planning:start:{$this->project->getKey()}", 10);
        $this->assertTrue($held->get());

        try {
            app(MonthPlanner::class)->start($this->project);
            $this->fail('Starting a month should wait for the lock rather than race it.');
        } catch (LockTimeoutException) {
            $this->assertSame(0, PipelineRun::query()->where('pipeline', 'planning')->count());
        } finally {
            $held->release();
        }
    }

    #[Test]
    public function the_button_and_the_assistant_share_the_same_guard(): void
    {
        $this->post('/content/plan')->assertRedirect();

        // The tool is the second caller, and a model presses faster than a
        // person — so this is the pair that has to agree, not the button with
        // itself.
        $tool = collect(app(MarketingTools::class)->all())
            ->firstWhere(static fn ($candidate): bool => $candidate->getName() === 'plan_month');

        $this->assertNotNull($tool);

        $result = ($tool->getCallback())();

        $this->assertFalse($result['ok']);
        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'planning')->count());
    }

    #[Test]
    public function another_projects_run_does_not_hold_this_one_off(): void
    {
        $theirs = Project::factory()->create();

        app(CurrentProject::class)->run($theirs, static function () use ($theirs): void {
            app(MonthPlanner::class)->start($theirs);
        });

        $this->assertNotNull(app(MonthPlanner::class)->start($this->project));
    }
}
