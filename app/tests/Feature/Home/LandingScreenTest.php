<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\User;
use App\Onboarding\WebsiteChecklist;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one landing screen.
 *
 * This file is what is left of `DashboardTest` after the dashboard stopped
 * being a screen, and what it kept is the point: the run panel, whose value is
 * entirely in the corrections baked into its two queries, and the empty state,
 * which is the only part of that page that said something Home did not.
 *
 * What it does **not** keep is the reporting — published counts, targeted search
 * volume, citation coverage, impressions, and two orderings of the same list of
 * units. Each was a fact about the past that changed no decision, and each was
 * already on the screen that owns it.
 */
final class LandingScreenTest extends TestCase
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

        // Not deferred, and asserted on the first render for that reason: a
        // project in its first hour has nothing else on this screen, so a
        // deferred run panel means the launch renders as an empty page.
        $this->actingAs($operator)
            ->get('/home')
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
                ->etc()
            );
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
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Leaving the old one up means an operator who has fixed the
                // cause sees the same error every time they open the page, and
                // learns to ignore the panel that exists to be read.
                ->has('work.failed', 1)
                ->where('work.failed.0.pipeline', 'generation')
                ->etc()
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
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('work.active', [])
                ->etc()
            );

        $this->assertNotSame($theirs->getKey(), $project->getKey());
    }

    #[Test]
    public function an_operator_with_no_project_is_pointed_at_the_wizard(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project', null)
                ->where('hasProjects', false)
                ->etc()
            );
    }

    #[Test]
    public function the_old_dashboard_address_lands_on_the_one_screen(): void
    {
        [$operator] = $this->operatorIn(OnboardingStatus::Active);

        // Kept as a redirect rather than deleted: the name is linked from the
        // onboarding wizard and from anything that ever sent somebody the URL,
        // and a 404 for those is a worse answer than the screen that replaced
        // it.
        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertRedirect('/home');
    }

    #[Test]
    public function the_article_half_of_the_engine_is_reported(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        app(CurrentProject::class)->run($project, function (): void {
            // Planned and approved, none of it out. The shape this band exists
            // for — a pipeline that has quietly stopped shipping.
            ContentItem::factory()->count(3)->create([
                'type' => ContentItemType::Explainer,
                'state' => ContentItemState::Idea,
            ]);
            ContentItem::factory()->count(2)->create([
                'type' => ContentItemType::Explainer,
                'state' => ContentItemState::Approved,
            ]);
        });

        $this->actingAs($operator)
            ->withHeaders($this->partial('halves'))
            ->get('/home')
            ->assertOk()
            ->assertJsonPath('props.halves.articles.planned', 3)
            ->assertJsonPath('props.halves.articles.approved', 2)
            ->assertJsonPath('props.halves.articles.published', 0);
    }

    #[Test]
    public function the_morning_is_counted_once(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        app(CurrentProject::class)->run($project, function (): void {
            ContentItem::factory()->count(2)->create([
                'type' => ContentItemType::Explainer,
                'state' => ContentItemState::Approved,
            ]);
            ContentItem::factory()->create([
                'type' => ContentItemType::Explainer,
                'state' => ContentItemState::Draft,
            ]);
        });

        $this->actingAs($operator)
            ->withHeaders($this->partial('needs'))
            ->get('/home')
            ->assertOk()
            ->assertJsonPath('props.needs.article_approvals', 2)
            ->assertJsonPath('props.needs.article_drafts', 1)
            ->assertJsonPath('props.needs.total', 3);
    }

    #[Test]
    public function a_visibility_score_never_measured_reads_as_unmeasured_and_never_as_zero(): void
    {
        [$operator] = $this->operatorIn(OnboardingStatus::Active);

        // Never asked is not the same as asked and absent from every answer,
        // and both would render as 0% if the null were coerced.
        $this->actingAs($operator)
            ->withHeaders($this->partial('figures'))
            ->get('/home')
            ->assertOk()
            ->assertJsonPath('props.figures.visibility.score', null)
            ->assertJsonPath('props.figures.visibility.last_asked_on', null);
    }

    #[Test]
    public function an_empty_account_is_not_told_it_has_reviewed_content(): void
    {
        [, $project] = $this->operatorIn(OnboardingStatus::Active);

        $steps = app(CurrentProject::class)->run($project, fn (): array => WebsiteChecklist::for($project));

        $review = collect($steps)->firstWhere('key', 'approve');
        $this->assertNotNull($review);
        $this->assertFalse($review['done']);
        $this->assertTrue($review['locked']);
    }

    #[Test]
    public function connecting_google_is_a_setup_step_rather_than_a_card(): void
    {
        [$operator] = $this->operatorIn(OnboardingStatus::Active);

        // It had a panel of its own on the dashboard, which is a setup step
        // wearing a card — and folding that screen in would have left the step
        // with nowhere to be announced at all.
        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $checklist = $page->toArray()['props']['checklist'];
                $this->assertIsArray($checklist);

                $google = collect($checklist)
                    ->firstWhere(static fn (mixed $step): bool => is_array($step)
                        && ($step['key'] ?? null) === 'google');

                $this->assertIsArray($google);
                $this->assertFalse($google['done']);
                $this->assertSame('See what it did', $google['group']);
            });
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
            'X-Inertia-Partial-Component' => 'home/index',
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
