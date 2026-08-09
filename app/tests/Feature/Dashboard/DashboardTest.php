<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ContentItemState;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_launching_project_shows_the_work_rather_than_a_grid_of_zeroes(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Launching);

        app(CurrentProject::class)->run($project, function (): void {
            $run = PipelineRun::query()->create([
                'pipeline' => 'research',
                'status' => PipelineRunStatus::Running,
                'started_at' => now(),
            ]);

            PipelineStep::query()->create([
                'pipeline_run_id' => $run->getKey(),
                'step_key' => 'seed_expansion',
                'position' => 0,
                'status' => PipelineStepStatus::Succeeded,
            ]);

            PipelineStep::query()->create([
                'pipeline_run_id' => $run->getKey(),
                'step_key' => 'cluster_topics',
                'position' => 1,
                'status' => PipelineStepStatus::Running,
            ]);
        });

        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('work.launching', true)
                ->where('work.active.0.pipeline', 'research')
                ->where('work.active.0.total_steps', 2)
                ->where('work.active.0.done_steps', 1)
                // The step being worked on right now, not a percentage: the
                // steps are not equal in length and a bar drawn from them moves
                // in lies.
                ->where('work.active.0.current_step', 'cluster_topics')
            );
    }

    #[Test]
    public function a_settled_project_reports_the_numbers_this_engine_actually_measures(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        app(CurrentProject::class)->run($project, function (): void {
            ContentItem::factory()->create([
                'state' => ContentItemState::Published,
                'topic_volume' => 900,
                'citations' => ['chatgpt' => true, 'perplexity' => false],
                'citations_checked_at' => now(),
            ]);

            ContentItem::factory()->create([
                'state' => ContentItemState::Idea,
                'topic_volume' => 100,
                'scheduled_for' => now()->addWeek(),
            ]);
        });

        // The numbers are deferred, so they arrive on the follow-up request the
        // page makes rather than on the page itself. Asking for them by name is
        // what the browser does a moment later.
        $this->actingAs($operator)
            ->withHeaders($this->partial('stats,upcoming'))
            ->get('/dashboard')
            ->assertOk()
            // Asserted as JSON rather than through AssertableInertia: that
            // helper reads the page out of the rendered view, and a partial
            // reload never renders one.
            ->assertJsonPath('props.stats.published', 1)
            ->assertJsonPath('props.stats.planned', 1)
            ->assertJsonPath('props.stats.targeted_volume', 1000)
            ->assertJsonPath('props.stats.citations.checked', 1)
            ->assertJsonPath('props.stats.citations.cited', 1)
            ->assertJsonPath('props.upcoming.0.topic_volume', 100);
    }

    #[Test]
    public function dashboard_lists_one_upcoming_entry_per_multilingual_unit(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        app(CurrentProject::class)->run($project, function (): void {
            $english = ContentItem::factory()->create([
                'locale' => 'en',
                'title' => 'Window cleaning',
                'scheduled_for' => now()->addWeek(),
            ]);

            ContentItem::factory()->inGroup($english->locale_group_id, 'pt-PT')->create([
                'title' => 'Limpeza de janelas',
                'scheduled_for' => now()->addWeek(),
            ]);
        });

        $this->actingAs($operator)
            ->withHeaders($this->partial('upcoming'))
            ->get('/dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'props.upcoming')
            ->assertJsonPath('props.upcoming.0.title', 'Window cleaning')
            ->assertJsonPath('props.upcoming.0.locales', ['en', 'pt-PT']);
    }

    #[Test]
    public function the_empty_cards_say_whether_google_is_connected(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        $this->actingAs($operator)
            ->withHeaders($this->partial('connected'))
            ->get('/dashboard')
            ->assertOk()
            // Nothing connected. A card reading "—" is otherwise
            // indistinguishable from a project nobody has visited yet, and the
            // operator cannot act on the difference they cannot see.
            ->assertJsonPath('props.connected.search_console', false)
            ->assertJsonPath('props.connected.analytics', false);

        app(CurrentProject::class)->run($project, function (): void {
            ProjectIntegration::factory()->create();
        });

        $this->actingAs($operator)
            ->withHeaders($this->partial('connected'))
            ->get('/dashboard')
            ->assertOk()
            ->assertJsonPath('props.connected.search_console', true)
            ->assertJsonPath('props.connected.analytics', true);
    }

    #[Test]
    public function engagement_is_reported_beside_the_search_numbers(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        app(CurrentProject::class)->run($project, function (): void {
            $unit = ContentItem::factory()->create(['state' => ContentItemState::Published]);

            ContentMetric::query()->create([
                'content_item_id' => $unit->getKey(),
                'measured_on' => now()->subDay()->toDateString(),
                'impressions' => 500,
                'clicks' => 30,
                'sessions' => 40,
                'engaged_sessions' => 26,
                'engagement_seconds' => 1800,
            ]);
        });

        $this->actingAs($operator)
            ->withHeaders($this->partial('stats'))
            ->get('/dashboard')
            ->assertOk()
            ->assertJsonPath('props.stats.search.impressions', 500)
            ->assertJsonPath('props.stats.engagement.sessions', 40)
            ->assertJsonPath('props.stats.engagement.engaged', 26);
    }

    #[Test]
    public function a_failure_the_pipeline_has_recovered_from_stops_being_news(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        app(CurrentProject::class)->run($project, function (): void {
            PipelineRun::query()->create([
                'pipeline' => 'research',
                'status' => PipelineRunStatus::Failed,
                'failed_step_key' => 'fetch_keywords',
                'error' => ['message' => 'HTTP request returned status code 401'],
                'finished_at' => now()->subHours(6),
            ]);

            // Same pipeline, later, fine. Whatever caused the 401 was fixed.
            PipelineRun::query()->create([
                'pipeline' => 'research',
                'status' => PipelineRunStatus::Completed,
                'finished_at' => now()->subHour(),
            ]);

            // A different pipeline that has not recovered still counts.
            PipelineRun::query()->create([
                'pipeline' => 'generation',
                'status' => PipelineRunStatus::Failed,
                'failed_step_key' => 'write_draft',
                'error' => ['message' => 'still broken'],
                'finished_at' => now()->subMinutes(30),
            ]);
        });

        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Leaving the old one up means an operator who has fixed the
                // cause sees the same error every time they open the page, and
                // learns to ignore the card that exists to be read.
                ->has('work.failed', 1)
                ->where('work.failed.0.pipeline', 'generation')
            );
    }

    #[Test]
    public function another_projects_work_never_appears(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        $theirs = Project::factory()->create();

        app(CurrentProject::class)->run($theirs, function (): void {
            PipelineRun::query()->create([
                'pipeline' => 'generation',
                'status' => PipelineRunStatus::Running,
            ]);
        });

        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('work.active', []));

        $this->assertNotSame($theirs->getKey(), $project->getKey());
    }

    #[Test]
    public function an_operator_with_no_project_is_pointed_at_the_wizard(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project', null)
                ->where('hasProjects', false)
            );
    }

    /**
     * The headers a browser sends when it comes back for a deferred prop.
     *
     * @return array<string, string>
     */
    private function partial(string $props): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => $props,
        ];
    }

    /**
     * @return array{User, Project}
     */
    private function operatorIn(OnboardingStatus $status): array
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create(['onboarding_status' => $status]);
        $operator->projects()->attach($project);

        return [$operator, $project];
    }
}
