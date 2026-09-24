<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Ai\Assistant\MarketingTools;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Engine\MonthPlanner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        ContentItem::factory()->create();
    }

    #[Test]
    public function it_starts_one_month(): void
    {
        $run = app(MonthPlanner::class)->start($this->project);

        $this->assertSame('planning', $run->pipeline);
    }

    #[Test]
    public function a_second_ask_while_one_is_in_flight_returns_the_same_work(): void
    {
        $first = app(MonthPlanner::class)->start($this->project);

        $this->assertSame($first->id, app(MonthPlanner::class)->start($this->project)->id);
        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'planning')->count());
    }

    #[Test]
    public function an_empty_idea_pool_reuses_research_and_keeps_the_billing_period(): void
    {
        $empty = Project::factory()->create();

        // A billed project plans its billing period; a month is asked for by
        // the one the period starts in, and the plan is filed under it.
        $period = ProjectSubscription::query()->where('project_id', $empty->id)->firstOrFail()
            ->period_started_at?->copy()->setTimezone($empty->timezone);
        $this->assertNotNull($period);
        $month = $period->format('Y-m');

        $first = app(MonthPlanner::class)->start($empty, $month);
        $second = app(MonthPlanner::class)->start($empty, $month);
        $this->assertSame('research', $first->pipeline);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($month.'-01', $second->context['article_plan_month']);
        $this->assertTrue($period->equalTo(Carbon::parse($second->context['article_plan_period'])));
        $this->assertSame(1, PipelineRun::acrossProjects()->where('project_id', $empty->id)->count());
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

        $this->assertTrue($result['ok']);
        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'planning')->count());
    }

    #[Test]
    public function another_projects_run_does_not_hold_this_one_off(): void
    {
        $theirs = Project::factory()->create();

        app(CurrentProject::class)->run($theirs, static function () use ($theirs): void {
            app(MonthPlanner::class)->start($theirs);
        });

        $this->assertSame($this->project->id, app(MonthPlanner::class)->start($this->project)->project_id);
    }
}
