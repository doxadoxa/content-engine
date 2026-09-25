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
    public function research_starts_when_onboarding_finishes(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();

        $run = app(ProjectLaunch::class)->begin($project);

        $this->assertSame('research', $run->pipeline);
        $this->assertSame(OnboardingStatus::Launching, $project->refresh()->onboarding_status);
        $this->assertSame(1, PipelineRun::acrossProjects()->where('pipeline', 'research')->count());
    }

    #[Test]
    public function a_finished_run_advances_the_chain_exactly_once(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();
        app(ProjectLaunch::class)->begin($project);
        $this->researchedIdea($project);

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
    public function research_that_found_nothing_settles_the_launch_without_writing_anything(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();
        $run = app(ProjectLaunch::class)->begin($project);

        $this->assertNotNull($run);
        $this->assertSame('research', $run->pipeline);

        // Finishing research is not permission to plan or draft on its own: an
        // empty idea pool leaves nothing to plan, and the launch settles
        // rather than waiting on work that will never start.
        $run->forceFill(['status' => PipelineRunStatus::Completed, 'finished_at' => now()])->save();
        PipelineRunFinished::dispatch($run);

        $this->assertSame(OnboardingStatus::Active, $project->refresh()->onboarding_status);
        $this->assertFalse(PipelineRun::acrossProjects()->whereIn('pipeline', ['planning', 'generation'])->exists());
        $this->assertSame(0, ContentItem::acrossProjects()->count());
    }

    #[Test]
    public function planning_starts_when_research_finishes(): void
    {
        Queue::fake();

        $project = Project::factory()->onboarding()->create();
        $launch = app(ProjectLaunch::class);
        $launch->begin($project);
        $this->researchedIdea($project);

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

        $launch = app(ProjectLaunch::class);
        $launch->begin($project);

        $planId = '';
        $plannedIds = [];
        $unplannedId = '';
        app(CurrentProject::class)->run($project, function () use ($project, &$planId, &$plannedIds, &$unplannedId): void {
            // The single monthly plan the launch flow keeps in production.
            $plan = ContentPlan::query()->firstOrCreate([
                'month' => now()->startOfMonth(),
            ]);
            $planId = $plan->getKey();

            $plannedIds = ContentItem::factory()->count(5)->create([
                'state' => ContentItemState::Idea,
                'locale' => $project->default_locale,
                'content_plan_id' => $plan->getKey(),
                'scheduled_for' => now()->addDays(1),
            ])->pluck('id')->all();

            $unplannedId = ContentItem::factory()->create([
                'state' => ContentItemState::Idea,
                'locale' => $project->default_locale,
                'scheduled_for' => now()->addDays(1),
            ])->getKey();
        });

        // Planned ideas are the launch material. The planner must never be
        // invoked with already-scheduled unplanned ideas, because its capacity
        // guard correctly reads those dates as a full calendar.
        $this->assertSame(0, $this->generationRuns());
        $finished = $this->finished($project, 'planning');
        $finished->forceFill(['context' => ['planning.plan_id' => $planId]])->save();
        $launch->advance($finished);

        // Three, not five: enough that the dashboard has something real inside
        // the hour, not so many that an unopened project spends a month's
        // budget before anybody looks at it.
        $this->assertSame(3, $this->generationRuns());
        $draftedIds = PipelineRun::acrossProjects()->where('pipeline', 'generation')->pluck('content_item_id')->all();
        $this->assertEmpty(array_diff($draftedIds, $plannedIds));
        $this->assertNotContains($unplannedId, $draftedIds);
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
        // audit knows one exists. An independent contour beside research:
        // neither needs the other, and one provider failing must not silently
        // cancel the other.
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

    private function researchedIdea(Project $project): void
    {
        app(CurrentProject::class)->run($project, fn (): ContentItem => ContentItem::factory()->create([
            'state' => ContentItemState::Idea, 'content_plan_id' => null,
        ]));
    }

    private function finished(Project $project, string $pipeline): PipelineRun
    {
        return app(CurrentProject::class)->run($project, function () use ($pipeline): PipelineRun {
            $run = PipelineRun::query()->where('pipeline', $pipeline)
                ->whereIn('status', [PipelineRunStatus::Pending, PipelineRunStatus::Running])->first()
                ?? new PipelineRun(['pipeline' => $pipeline]);
            $run->forceFill(['status' => PipelineRunStatus::Completed, 'finished_at' => now()])->save();

            return $run;
        });
    }
}
