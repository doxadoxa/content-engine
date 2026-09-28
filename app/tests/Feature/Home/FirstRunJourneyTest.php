<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\BillingStatus;
use App\Enums\ContentItemState;
use App\Enums\OnboardingStatus;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\LlmPrompt;
use App\Models\LlmVisibilityAnswer;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The first run, told as it happens.
 *
 * A customer who had just paid opened Home to a grid of dashes and a spinner
 * below the fold, and read it as a product that had not started. These are
 * about the journey that now leads the screen: which step it says is happening,
 * and — as much — when it stops saying anything at all.
 */
final class FirstRunJourneyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_launch_in_research_says_it_is_finding_topics_after_reading_the_site(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Launching, [
            // What the wizard leaves behind: the site was read before launch.
            'site_analysis' => ['name' => 'Bright Windows', 'description' => 'Window cleaning'],
        ]);

        $this->during($project, fn () => PipelineRun::factory()->running()->create(['pipeline' => 'research']));

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journey.steps.0.key', 'site')
                ->where('journey.steps.0.state', 'done')
                ->where('journey.steps.1.key', 'topics')
                ->where('journey.steps.1.state', 'active')
                ->where('journey.steps.2.state', 'upcoming')
                ->where('journey.steps.3.state', 'upcoming')
                ->where('journey.steps.4.key', 'ai')
                ->where('journey.steps.4.state', 'upcoming')
                ->etc()
            );
    }

    #[Test]
    public function a_site_nobody_has_read_yet_is_still_to_come(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Launching);

        $this->during($project, fn () => PipelineRun::factory()->running()->create(['pipeline' => 'research']));

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journey.steps.0.state', 'upcoming')
                ->where('journey.steps.1.state', 'active')
                ->etc()
            );
    }

    #[Test]
    public function the_article_being_written_is_named_and_reachable(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        $article = $this->during($project, function (): ContentItem {
            $plan = ContentPlan::factory()->create();
            $article = ContentItem::factory()->inState(ContentItemState::Generating)->create([
                'content_plan_id' => $plan->getKey(),
                'title' => 'How often should you clean your windows?',
            ]);

            PipelineRun::factory()->create(['pipeline' => 'research', 'created_at' => now()->subHour()]);
            PipelineRun::factory()->running()->create([
                'pipeline' => 'generation',
                'content_item_id' => $article->getKey(),
            ]);

            return $article;
        });

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journey.steps.2.key', 'calendar')
                ->where('journey.steps.2.state', 'done')
                ->where('journey.steps.3.key', 'articles')
                ->where('journey.steps.3.state', 'active')
                ->where('journey.steps.3.subject', 'How often should you clean your windows?')
                ->where('journey.steps.3.subject_id', $article->getKey())
                ->etc()
            );
    }

    #[Test]
    public function a_finished_first_run_with_nothing_moving_steps_aside(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active, [
            'site_analysis' => ['name' => 'Bright Windows'],
        ]);

        $this->during($project, function () use ($project): void {
            // Inside the window, past the pause between jobs: a journey whose
            // every step has happened is over, however young the project.
            foreach (['site_audit', 'research', 'planning', 'generation', 'visibility'] as $pipeline) {
                PipelineRun::factory()->create([
                    'pipeline' => $pipeline,
                    'created_at' => now()->subHours(5),
                    'started_at' => now()->subHours(5),
                    'finished_at' => now()->subHours(4),
                ]);
            }

            $plan = ContentPlan::factory()->create();
            ContentItem::factory()->create(['content_plan_id' => $plan->getKey()]);
            ContentItem::factory()->draft()->create(['content_plan_id' => $plan->getKey()]);
            $prompt = LlmPrompt::factory()->for($project)->create();
            LlmVisibilityAnswer::factory()->for($project)->for($prompt, 'prompt')->create();
        });

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('journey')
                ->where('journey', null)
                ->etc()
            );
    }

    #[Test]
    public function the_pause_between_one_job_and_the_next_keeps_the_journey_up(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active, [
            'site_analysis' => ['name' => 'Bright Windows'],
        ]);

        $this->during($project, function (): void {
            // The launch finished ten minutes ago and the first AI check is
            // started by an hourly command. Nothing is in flight, and the
            // panel must not blink out while the scheduler gets round to it.
            PipelineRun::factory()->create([
                'pipeline' => 'generation',
                'created_at' => now()->subMinutes(40),
                'finished_at' => now()->subMinutes(10),
            ]);
            ContentItem::factory()->draft()->create();
        });

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journey.steps.3.state', 'done')
                ->where('journey.steps.4.state', 'upcoming')
                ->etc()
            );
    }

    #[Test]
    public function a_first_run_long_past_never_comes_back_as_a_journey(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active, [
            'site_analysis' => ['name' => 'Bright Windows'],
        ]);

        $this->during($project, function (): void {
            PipelineRun::factory()->create([
                'pipeline' => 'research',
                'created_at' => now()->subDays(3),
                'finished_at' => now()->subDays(3),
            ]);

            // A routine run finished minutes ago, and the AI step never
            // happened — no questions were ever set up. Neither is a reason to
            // tell somebody three days in that Avyo is getting started.
            PipelineRun::factory()->create([
                'pipeline' => 'planning',
                'finished_at' => now()->subMinutes(5),
            ]);
        });

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('journey')
                ->where('journey', null)
                ->etc()
            );
    }

    #[Test]
    public function later_work_is_the_run_panels_to_describe(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active);

        $this->during($project, function (): void {
            PipelineRun::factory()->create(['pipeline' => 'research', 'created_at' => now()->subWeeks(2)]);
            PipelineRun::factory()->running()->create(['pipeline' => 'generation']);
        });

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journey', null)
                ->where('work.active.0.pipeline', 'generation')
                ->etc()
            );
    }

    #[Test]
    public function a_trial_started_after_the_sample_is_a_first_run_of_its_own(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active, [
            'site_analysis' => ['name' => 'Bright Windows'],
        ]);

        // The card-free sample ran four days ago; the card went in an hour
        // ago, and the trial's first article is being written now. Measured
        // from the sample alone, this paying customer would get no journey.
        ProjectSubscription::query()->where('project_id', $project->getKey())->firstOrFail()->update([
            'plan' => 'growth',
            'status' => BillingStatus::Trialing,
            'period_started_at' => now()->subHour(),
            'trial_ends_at' => now()->addDays(3),
        ]);

        $this->during($project, function (): void {
            PipelineRun::factory()->create([
                'pipeline' => 'research',
                'created_at' => now()->subDays(4),
                'finished_at' => now()->subDays(4),
            ]);
            PipelineRun::factory()->running()->create(['pipeline' => 'generation']);
        });

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journey.steps.3.key', 'articles')
                ->where('journey.steps.3.state', 'active')
                ->etc()
            );
    }

    #[Test]
    public function a_pause_longer_than_the_scheduler_needs_ends_the_journey(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active, [
            'site_analysis' => ['name' => 'Bright Windows'],
        ]);

        $this->during($project, function (): void {
            // The AI step is still to come, but nothing has finished in over
            // an hour and a half and nothing is running: the scheduler is not
            // about to pick it up, and "Avyo is working" would be a claim.
            PipelineRun::factory()->create([
                'pipeline' => 'generation',
                'created_at' => now()->subHours(3),
                'finished_at' => now()->subMinutes(91),
            ]);
            ContentItem::factory()->draft()->create();
        });

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journey', null)
                ->etc()
            );
    }

    #[Test]
    public function a_run_that_failed_does_not_hold_the_journey_up(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active, [
            'site_analysis' => ['name' => 'Bright Windows'],
        ]);

        $this->during($project, function (): void {
            // Research stopped a minute ago. A chain that stopped is not work
            // in progress; the run panel is what says it stopped.
            PipelineRun::factory()->failed()->create([
                'pipeline' => 'research',
                'created_at' => now()->subMinutes(20),
                'finished_at' => now()->subMinute(),
            ]);
        });

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journey', null)
                ->where('work.failed.0.pipeline', 'research')
                ->etc()
            );
    }

    #[Test]
    public function a_monthly_renewal_is_not_a_first_run(): void
    {
        [$operator, $project] = $this->operatorIn(OnboardingStatus::Active, [
            'site_analysis' => ['name' => 'Bright Windows'],
        ]);

        // An active plan's period starts again every month. Only a trial's
        // start reopens the window; a renewal with generation running is the
        // run panel's to describe.
        ProjectSubscription::query()->where('project_id', $project->getKey())->firstOrFail()->update([
            'plan' => 'growth',
            'status' => BillingStatus::Active,
            'period_started_at' => now()->subHour(),
        ]);

        $this->during($project, function (): void {
            PipelineRun::factory()->create([
                'pipeline' => 'research',
                'created_at' => now()->subMonth(),
                'finished_at' => now()->subMonth(),
            ]);
            PipelineRun::factory()->running()->create(['pipeline' => 'generation']);
        });

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journey', null)
                ->etc()
            );
    }

    #[Test]
    public function another_projects_work_is_not_this_ones_journey(): void
    {
        [$operator] = $this->operatorIn(OnboardingStatus::Active);

        $theirs = Project::factory()->create();
        $this->during($theirs, fn () => PipelineRun::factory()->running()->create(['pipeline' => 'research']));

        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('journey')
                ->where('journey', null)
                ->etc()
            );
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $seed
     * @return T
     */
    private function during(Project $project, Closure $seed): mixed
    {
        return app(CurrentProject::class)->run($project, $seed);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{User, Project}
     */
    private function operatorIn(OnboardingStatus $status, array $attributes = []): array
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create(['onboarding_status' => $status, ...$attributes]);
        $operator->projects()->attach($project);

        return [$operator, $project];
    }
}
