<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\ContentItemState;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\User;
use App\Onboarding\ProjectLaunch;
use App\Pipelines\Events\PipelineRunFinished;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The chain that turns a finished wizard into a running project.
 *
 * Driven by the run-finished event rather than by a job that waits, so what
 * these tests do is finish a run and check what started next.
 */
final class ProjectLaunchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function research_and_the_studio_proposal_start_when_onboarding_finishes(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();

        $run = app(ProjectLaunch::class)->begin($project);

        $this->assertSame('research', $run->pipeline);
        $this->assertSame(OnboardingStatus::Launching, $project->refresh()->onboarding_status);
        $this->assertSame(1, PipelineRun::acrossProjects()->where('pipeline', 'research')->count());
        $this->assertSame(1, PipelineRun::acrossProjects()->where('pipeline', 'content_studio')->count());

        app(CurrentProject::class)->run($project, function (): void {
            $plan = ContentPlan::query()->firstOrFail();
            $run = PipelineRun::query()->where('pipeline', 'content_studio')->firstOrFail();

            $this->assertSame(now()->startOfMonth()->toDateString(), $plan->month->toDateString());
            $this->assertSame($plan->getKey(), $run->input['content_plan_id']);
            $this->assertSame('proposal', $run->input['action']);
        });
    }

    #[Test]
    public function a_finished_studio_proposal_queues_three_starter_channel_drafts(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();
        $launch = app(ProjectLaunch::class);
        $launch->begin($project);

        $proposal = PipelineRun::acrossProjects()
            ->where('pipeline', 'content_studio')
            ->where('input->action', 'proposal')
            ->firstOrFail();
        $proposal->forceFill([
            'status' => PipelineRunStatus::Completed,
            'finished_at' => now(),
        ])->save();

        $launch->advance($proposal);

        $starter = PipelineRun::acrossProjects()
            ->where('pipeline', 'content_studio')
            ->where('input->action', 'generate_week')
            ->firstOrFail();

        $this->assertTrue($starter->input['initial']);
        $this->assertSame($proposal->input['content_plan_id'], $starter->input['content_plan_id']);
    }

    #[Test]
    public function a_finished_run_advances_the_chain_exactly_once(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();
        app(ProjectLaunch::class)->begin($project);

        // Through the event rather than by calling advance() directly, which is
        // the whole point: every other test here reaches past the wiring, and
        // the wiring is where the bug was. The listener was registered by hand
        // *and* auto-discovered from app/Listeners, so one finished research
        // run started two planning runs and planned the month twice.
        PipelineRunFinished::dispatch($this->finished($project, 'research'));

        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('pipeline', 'planning')->count(),
            'A finished research run must start exactly one planning run.',
        );
    }

    #[Test]
    public function planning_starts_when_research_finishes(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();
        $launch = app(ProjectLaunch::class);
        $launch->begin($project);

        $launch->advance($this->finished($project, 'research'));

        $this->assertTrue(
            PipelineRun::acrossProjects()->where('pipeline', 'planning')->exists(),
            'Research finishing should have started planning.',
        );
    }

    #[Test]
    public function the_first_few_ideas_are_drafted_when_planning_finishes(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();

        app(CurrentProject::class)->run($project, function () use ($project): void {
            ContentItem::factory()->count(5)->create([
                'state' => ContentItemState::Idea,
                'locale' => $project->default_locale,
                'content_plan_id' => null,
                'scheduled_for' => now()->addDays(1),
            ]);
        });

        // Ideas that are not on a plan are not launch material: the chain
        // drafts what the planner scheduled, not everything ever researched.
        $this->assertSame(0, $this->generationRuns());

        app(CurrentProject::class)->run($project, function () use ($project): void {
            $plan = $project->contentPlans()->create([
                'month' => now()->startOfMonth(),
            ]);

            ContentItem::query()->update(['content_plan_id' => $plan->getKey()]);
        });

        $launch = app(ProjectLaunch::class);
        $launch->begin($project);
        $launch->advance($this->finished($project, 'planning'));

        // Three, not five: enough that the dashboard has something real inside
        // the hour, not so many that an unopened project spends a month's
        // budget before anybody looks at it.
        $this->assertSame(3, $this->generationRuns());
    }

    #[Test]
    public function the_project_goes_active_once_the_last_draft_lands(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();
        $launch = app(ProjectLaunch::class);
        $launch->begin($project);

        // The only run left is the one that just finished.
        PipelineRun::acrossProjects()->update(['status' => PipelineRunStatus::Completed]);

        $launch->advance($this->finished($project, 'generation'));

        $project->refresh();

        $this->assertSame(OnboardingStatus::Active, $project->onboarding_status);
        $this->assertNotNull($project->onboarded_at);
    }

    #[Test]
    public function a_failed_run_stops_the_chain_rather_than_planning_from_nothing(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();
        $launch = app(ProjectLaunch::class);
        $launch->begin($project);

        $failed = $this->finished($project, 'research');
        $failed->forceFill(['status' => PipelineRunStatus::Failed])->save();

        $launch->advance($failed);

        // No planning: a month planned from an empty idea pool looks like a
        // thin calendar rather than the failure that caused it.
        $this->assertFalse(
            PipelineRun::acrossProjects()->where('pipeline', 'planning')->exists(),
        );

        // But the project settles, so the dashboard stops saying "setting up"
        // forever.
        $this->assertSame(OnboardingStatus::Active, $project->refresh()->onboarding_status);
    }

    #[Test]
    public function a_failed_studio_proposal_does_not_cancel_the_research_chain(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();
        $launch = app(ProjectLaunch::class);
        $launch->begin($project);

        $studio = PipelineRun::acrossProjects()
            ->where('pipeline', 'content_studio')
            ->firstOrFail();
        $studio->forceFill(['status' => PipelineRunStatus::Failed])->save();

        $launch->advance($studio);

        $this->assertSame(OnboardingStatus::Launching, $project->refresh()->onboarding_status);
        $this->assertTrue(
            PipelineRun::acrossProjects()->where('pipeline', 'research')->exists(),
        );
        $this->assertFalse(
            PipelineRun::acrossProjects()->where('pipeline', 'planning')->exists(),
        );
    }

    #[Test]
    public function a_launch_whose_chain_died_is_settled_when_somebody_looks(): void
    {
        Queue::fake();

        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create();
        $operator->projects()->attach($project);

        app(ProjectLaunch::class)->begin($project);

        // The chain is driven by an event, and an event that never arrives —
        // worker killed mid-run, queue drained by hand, release deployed
        // between two steps — leaves this project saying "setting up" with a
        // spinner and no way out.
        PipelineRun::acrossProjects()->update(['status' => PipelineRunStatus::Failed]);

        $this->assertSame(OnboardingStatus::Launching, $project->refresh()->onboarding_status);

        $this->actingAs($operator)->get('/home')->assertOk();

        $this->assertSame(OnboardingStatus::Active, $project->refresh()->onboarding_status);
    }

    #[Test]
    public function a_launch_still_working_is_not_settled_early(): void
    {
        Queue::fake();

        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create();
        $operator->projects()->attach($project);

        app(ProjectLaunch::class)->begin($project);

        // Research is still pending. Settling on a timer rather than on the
        // fact would cut a slow queue off mid-launch.
        $this->actingAs($operator)->get('/home')->assertOk();

        $this->assertSame(OnboardingStatus::Launching, $project->refresh()->onboarding_status);
    }

    #[Test]
    public function launching_a_project_also_reads_the_website_it_was_given(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create(['website_url' => 'https://example.test']);

        app(ProjectLaunch::class)->begin($project);

        // The wizard has just handed us a website, which is the only moment the
        // audit knows one exists. A third independent contour beside research
        // and the Studio: none of the three needs the others, and one provider
        // failing must not silently cancel the other two.
        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('pipeline', 'site_audit')->count(),
        );
    }

    #[Test]
    public function a_project_with_no_website_launches_without_an_audit(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create(['website_url' => null]);

        app(ProjectLaunch::class)->begin($project);

        // Refused where the caller is standing rather than as a failed run on
        // the operator's very first screen.
        $this->assertSame(
            0,
            PipelineRun::acrossProjects()->where('pipeline', 'site_audit')->count(),
        );
    }

    #[Test]
    public function a_crawl_still_running_does_not_hold_the_launch_spinner_on(): void
    {
        Queue::fake();

        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create(['website_url' => 'https://example.test']);
        $operator->projects()->attach($project);

        app(ProjectLaunch::class)->begin($project);

        // Everything the launch is actually waiting for has finished; only the
        // site audit is still going. A crawl of a hundred pages is ten minutes
        // of waiting on somebody else's server, and its result appears on a
        // different screen — so the dashboard must stop saying "setting up".
        PipelineRun::acrossProjects()
            ->whereNot('pipeline', 'site_audit')
            ->update(['status' => PipelineRunStatus::Completed, 'finished_at' => now()]);

        $this->actingAs($operator)->get('/home')->assertOk();

        $this->assertSame(OnboardingStatus::Active, $project->refresh()->onboarding_status);
        $this->assertTrue(
            PipelineRun::acrossProjects()->where('pipeline', 'site_audit')->exists(),
            'And the audit is still there, running.',
        );
    }

    #[Test]
    public function a_project_that_is_not_launching_is_left_alone(): void
    {
        Queue::fake();

        $project = Project::factory()->create(['onboarding_status' => OnboardingStatus::Active]);

        app(ProjectLaunch::class)->advance($this->finished($project, 'research'));

        $this->assertFalse(
            PipelineRun::acrossProjects()->where('pipeline', 'planning')->exists(),
            'A settled project must not have its chain restarted by an ordinary scheduled run.',
        );
    }

    private function generationRuns(): int
    {
        return PipelineRun::acrossProjects()->where('pipeline', 'generation')->count();
    }

    private function finished(Project $project, string $pipeline): PipelineRun
    {
        return app(CurrentProject::class)->run($project, fn (): PipelineRun => PipelineRun::query()->create([
            'pipeline' => $pipeline,
            'status' => PipelineRunStatus::Completed,
            'finished_at' => now(),
        ]));
    }
}
