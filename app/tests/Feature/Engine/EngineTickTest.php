<?php

declare(strict_types=1);

namespace Tests\Feature\Engine;

use App\Enums\ContentItemState;
use App\Enums\PipelineRunStatus;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Publishing\Articles\ArticleSchedules;
use App\Support\Tenancy\CurrentProject;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The thing that makes this a service rather than a set of commands.
 *
 * Every pipeline here was startable by hand and started by nothing: a project
 * could be onboarded, planned, and then sit while its calendar filled with
 * dates nobody would ever write.
 */
final class EngineTickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function a_unit_due_soon_starts_writing_itself(): void
    {
        $project = $this->project();

        $this->inProject($project, function () use ($project): void {
            $plan = $project->contentPlans()->create(['month' => now()->startOfMonth()]);

            ContentItem::factory()->create([
                'state' => ContentItemState::Idea,
                'content_plan_id' => $plan->getKey(),
                'scheduled_for' => now()->addDay(),
            ]);
        });

        $this->tick();

        $this->assertTrue(
            PipelineRun::acrossProjects()->where('pipeline', 'generation')->exists(),
            'A unit due tomorrow should have started drafting.',
        );
    }

    #[Test]
    public function one_locale_of_a_unit_is_written_at_a_time(): void
    {
        $project = $this->project(['default_locale' => 'pt-PT', 'locales' => ['pt-PT', 'en', 'ru']]);

        $units = $this->inProject($project, function () use ($project): array {
            $plan = $project->contentPlans()->create(['month' => now()->startOfMonth()]);

            $portuguese = ContentItem::factory()->create([
                'state' => ContentItemState::Idea,
                'content_plan_id' => $plan->getKey(),
                'scheduled_for' => now()->addDay(),
                'locale' => 'pt-PT',
            ]);

            foreach (['en', 'ru'] as $locale) {
                ContentItem::factory()
                    ->inGroup($portuguese->locale_group_id, $locale)
                    ->create([
                        'state' => ContentItemState::Idea,
                        'content_plan_id' => $plan->getKey(),
                        'scheduled_for' => now()->addDay(),
                    ]);
            }

            return ['lead' => $portuguese];
        });

        $this->tick();

        $started = PipelineRun::acrossProjects()->where('pipeline', 'generation')->get();

        // One run, not three. Three locales of one subject starting in the same
        // second can share nothing, and each was buying its own hero and its own
        // three section pictures — twelve paid images for one topic.
        $this->assertCount(1, $started, 'The locales of one unit should not start together.');

        // And it is the project's own language that leads, because whichever
        // runs first chooses the pictures the whole set will carry.
        $this->assertSame($units['lead']->getKey(), $started->first()?->content_item_id);
    }

    #[Test]
    public function different_subjects_still_start_together(): void
    {
        $project = $this->project();

        $this->inProject($project, function () use ($project): void {
            $plan = $project->contentPlans()->create(['month' => now()->startOfMonth()]);

            // Two unrelated units. Sequencing is about what a unit's locales
            // can share; two subjects share nothing, and serialising them would
            // cost the project its throughput for no saving.
            ContentItem::factory()->count(2)->create([
                'state' => ContentItemState::Idea,
                'content_plan_id' => $plan->getKey(),
                'scheduled_for' => now()->addDay(),
            ]);
        });

        $this->tick();

        $this->assertSame(2, PipelineRun::acrossProjects()->where('pipeline', 'generation')->count());
    }

    #[Test]
    public function a_run_that_died_days_ago_does_not_hold_the_project_shut(): void
    {
        $project = $this->project();

        $this->inProject($project, function () use ($project): void {
            $plan = $project->contentPlans()->create(['month' => now()->startOfMonth()]);

            ContentItem::factory()->create([
                'state' => ContentItemState::Idea,
                'content_plan_id' => $plan->getKey(),
                'scheduled_for' => now()->addDay(),
            ]);

            // The incident, in one row: a `visibility` run stuck at `running`
            // since 2026-08-07 because its failure handler failed the same way
            // its step did. `isBusy()` waits for any live run in the contour, so
            // the project drafted nothing for two days and said "still working"
            // once an hour while it did.
            PipelineRun::factory()->create([
                'pipeline' => 'visibility',
                'status' => PipelineRunStatus::Running,
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
                'started_at' => now()->subDays(2),
            ]);
        });

        $this->tick();

        $this->assertTrue(
            PipelineRun::acrossProjects()->where('pipeline', 'generation')->exists(),
            'Wreckage in the contour is not a reason to stop writing.',
        );
    }

    #[Test]
    public function a_run_that_is_merely_slow_is_still_waited_for(): void
    {
        $project = $this->project();

        $this->inProject($project, function () use ($project): void {
            $plan = $project->contentPlans()->create(['month' => now()->startOfMonth()]);

            ContentItem::factory()->create([
                'state' => ContentItemState::Idea,
                'content_plan_id' => $plan->getKey(),
                'scheduled_for' => now()->addDay(),
            ]);

            // A visibility sweep is sixty paid calls and legitimately takes
            // many minutes. The bound above must not turn that into a second
            // copy of the same work.
            PipelineRun::factory()->create([
                'pipeline' => 'visibility',
                'status' => PipelineRunStatus::Running,
                'started_at' => now()->subMinutes(20),
            ]);
        });

        $this->tick();

        $this->assertFalse(
            PipelineRun::acrossProjects()->where('pipeline', 'generation')->exists(),
            'The contour still waits for work that is only slow.',
        );
    }

    #[Test]
    public function a_billing_period_with_nothing_on_it_is_planned_now(): void
    {
        // Late in the calendar month, with ideas waiting and nothing scheduled.
        // A billed project plans its billing period rather than a calendar
        // month, and it plans it now: waiting for dates to run out means the
        // period starts with an empty calendar and its articles start late.
        Carbon::setTestNow(Carbon::parse('2026-08-29'));

        $project = $this->project();

        $this->inProject($project, function (): void {
            ContentItem::factory()->count(6)->create([
                'state' => ContentItemState::Idea,
                'content_plan_id' => null,
                'scheduled_for' => null,
            ]);
        });

        $this->tick();

        $run = PipelineRun::acrossProjects()->where('pipeline', 'planning')->first();

        $this->assertNotNull($run, 'The current billing period should have been planned.');

        // The period the subscription is in — started three days ago, in
        // August — and not the calendar month about to start. A renewal is
        // never planned before it is confirmed.
        $period = ProjectSubscription::query()->where('project_id', $project->getKey())->firstOrFail()->period_started_at;
        $this->assertSame('2026-08-01', $run->input['month'] ?? null);
        $this->assertTrue($period?->equalTo(Carbon::parse($run->input['article_period_started_at'] ?? '')));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_unit_due_far_off_is_left_alone(): void
    {
        $project = $this->project();

        $this->inProject($project, function () use ($project): void {
            $plan = $project->contentPlans()->create(['month' => now()->startOfMonth()]);

            ContentItem::factory()->count(4)->create([
                'state' => ContentItemState::Idea,
                'content_plan_id' => $plan->getKey(),
                'scheduled_for' => now()->addDays(4),
            ]);
        });

        $this->tick();

        // Written the day before, so there is an evening to read it in. Further
        // ahead and a reviewer is checking work nobody will see for a week.
        $this->assertFalse(
            PipelineRun::acrossProjects()->where('pipeline', 'generation')->exists(),
        );
    }

    #[Test]
    public function a_project_that_publishes_without_review_approves_its_own_drafts(): void
    {
        $project = $this->project(['autopublish' => true, 'onboarding' => ['article_automation_started_at' => now()->toIso8601String()]]);

        $draft = $this->inProject($project, fn (): ContentItem => ContentItem::factory()->create([
            'state' => ContentItemState::Draft,
            'body_markdown' => $this->publishableBody(),
        ]));

        $this->automaticSchedule($project, $draft);
        $this->tick();

        // The wizard has offered this since onboarding existed and nothing read
        // it: only the channel flag was honoured, and only after a human
        // clicked approve. An operator who asked not to be asked was asked.
        $this->assertSame(ContentItemState::Approved, $draft->refresh()->state);
    }

    #[Test]
    public function an_article_that_fails_a_critical_check_is_held_back(): void
    {
        $project = $this->project(['autopublish' => true, 'onboarding' => ['article_automation_started_at' => now()->toIso8601String()]]);

        $draft = $this->inProject($project, fn (): ContentItem => ContentItem::factory()->create([
            'state' => ContentItemState::Draft,
            // Every tell in the style guide, and no section saying where this
            // does not fit. Publishing without review is a privilege, not a
            // bypass — otherwise the verdict is a number nobody acts on, which
            // is what happened to the autopublish flag itself.
            'body_markdown' => 'We delve into the robust and seamless realm of cleaning. '
                .'Moreover, it is worth noting the tapestry of options available. '
                .'Furthermore, we empower you to leverage a myriad of solutions.',
        ]));

        $this->automaticSchedule($project, $draft);
        $this->tick();

        $this->assertSame(ContentItemState::Draft, $draft->refresh()->state);
    }

    #[Test]
    public function a_money_or_health_project_is_never_auto_approved(): void
    {
        $project = $this->project(['autopublish' => true, 'is_ymyl' => true, 'onboarding' => ['article_automation_started_at' => now()->toIso8601String()]]);

        $draft = $this->inProject($project, fn (): ContentItem => ContentItem::factory()->create([
            'state' => ContentItemState::Draft,
            'body_markdown' => $this->publishableBody(),
        ]));

        $this->automaticSchedule($project, $draft);
        $this->tick();

        $this->assertSame(ContentItemState::Draft, $draft->refresh()->state);
    }

    #[Test]
    public function nothing_starts_while_something_is_still_running(): void
    {
        $project = $this->project();

        $this->inProject($project, function () use ($project): void {
            $plan = $project->contentPlans()->create(['month' => now()->startOfMonth()]);

            ContentItem::factory()->create([
                'state' => ContentItemState::Idea,
                'content_plan_id' => $plan->getKey(),
                'scheduled_for' => now()->addDay(),
            ]);

            PipelineRun::query()->create(['pipeline' => 'research', 'status' => 'running']);
        });

        $this->tick();

        // These pipelines feed each other. Starting planning while research is
        // still filling the pool plans a month from half of it.
        $this->assertFalse(
            PipelineRun::acrossProjects()->where('pipeline', 'generation')->exists(),
        );
    }

    /**
     * `artisan()` is declared as returning `PendingCommand|int`, and
     * assertSuccessful() only records the expectation — the command runs in
     * __destruct(), so it has to be run explicitly.
     */
    private function tick(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('engine:tick');

        $command->assertSuccessful()->run();
    }

    /**
     * A body that clears the checks the style guide calls non-negotiable: no
     * banned words, a section naming where this does not fit, and sentences
     * that are not all the same length.
     */
    private function publishableBody(): string
    {
        return "## Where a weekly clean is the wrong call\n\n"
            .'A deep clean takes about three hours. Bathrooms take longest. We bring our own '
            .'cloths and sprays. If you have marble, say so first, because it needs a '
            .'pH-neutral product and most supermarket sprays will etch the surface beyond '
            ."repair.\n\n"
            ."## What a visit covers\n\n"
            ."Most flats need one visit a week. Ovens take 45 minutes on their own.\n";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function project(array $attributes = []): Project
    {
        return Project::factory()->create(['weekly_target' => 3, ...$attributes]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inProject(Project $project, Closure $work): mixed
    {
        return app(CurrentProject::class)->run($project, $work);
    }

    private function automaticSchedule(Project $project, ContentItem $draft): void
    {
        $this->inProject($project, function () use ($draft): void {
            $draft->update(['factcheck' => ['passed' => true]]);
            $channel = Channel::factory()->create(['verified_at' => now(), 'autopublish' => true]);
            app(ArticleSchedules::class)->scheduleNew($draft, now()->addDay(), $channel);
        });
    }
}
