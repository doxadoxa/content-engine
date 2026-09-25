<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Ai\FakeModelGateway;
use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Billing\PlanCatalog;
use App\Enums\BillingStatus;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Models\ArticleSchedule;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Onboarding\ProjectLaunch;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Events\PipelineRunFinished;
use App\Pipelines\Steps\Planning\ScheduleCalendar;
use App\Pipelines\Steps\Planning\SelectionPayload;
use App\Pipelines\Steps\Planning\SelectTopics;
use App\Pipelines\Steps\Planning\TypeAndFlagUnits;
use App\Pipelines\Steps\Planning\TypingPayload;
use App\Support\Engine\ArticleWorkflow;
use App\Support\Engine\MonthPlanner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ArticleCalendarRestorationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        Queue::fake();
    }

    public function test_every_plan_includes_articles_and_a_calendar(): void
    {
        $catalog = app(PlanCatalog::class);
        $this->assertSame(30, $catalog->get('growth')->limit('articles'));
        $this->assertSame(1, $catalog->get('growth')->limit('content_plans'));
        $this->assertSame(12, $catalog->get('starter')->limit('articles'));
        $this->assertSame(1, $catalog->get('starter')->limit('content_plans'));
        $this->assertSame(3, $catalog->trial()->limit('articles'));
    }

    public function test_article_launch_research_continues_once(): void
    {
        $project = $this->project();
        $run = app(ProjectLaunch::class)->begin($project);
        $this->assertNotNull($run);
        $this->assertSame('research', $run->pipeline);
        $this->assertNotNull($project->refresh()->onboarding['article_automation_started_at'] ?? null);
        $this->ideas(2);
        $run->forceFill(['status' => PipelineRunStatus::Completed, 'finished_at' => now()])->save();
        PipelineRunFinished::dispatch($run);
        PipelineRunFinished::dispatch($run->refresh());
        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'planning')->count());
        $this->assertSame(OnboardingStatus::Launching, $project->refresh()->onboarding_status);
    }

    public function test_manual_month_survives_research_and_repeated_requests(): void
    {
        $project = $this->project();
        $run = app(MonthPlanner::class)->start($project, '2026-09-01');
        $again = app(MonthPlanner::class)->start($project, '2026-09-01');
        $this->assertSame($run->id, $again->id);
        $this->ideas(1);
        $run->forceFill(['status' => PipelineRunStatus::Completed])->save();
        $planned = app(MonthPlanner::class)->afterResearch($run);
        $this->assertNotNull($planned);
        // A billed project plans its confirmed billing period; the month is
        // only the calendar bucket that period starts in.
        $this->assertSame('2026-09-01', $planned->input['month']);
        $period = ProjectSubscription::query()->where('project_id', $project->id)->sole()->period_started_at;
        $this->assertTrue(Carbon::parse($planned->input['article_period_started_at'])->equalTo($period));
        $this->assertNull(app(MonthPlanner::class)->afterResearch($run));
        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'planning')->count());
    }

    public function test_empty_research_records_an_honest_no_plan_reason(): void
    {
        $project = $this->project();
        $run = app(MonthPlanner::class)->start($project);
        $run->forceFill(['status' => PipelineRunStatus::Completed])->save();
        $this->assertNull(app(MonthPlanner::class)->afterResearch($run));
        $this->assertSame('not_started', $run->refresh()->context['article_plan_result']);
        $this->assertStringContainsString('suitable new topic', $run->context['article_plan_error']);
        $this->assertDatabaseCount('content_plans', 0);
    }

    public function test_trial_calendar_is_capped_and_schedules_only_new_opted_in_articles(): void
    {
        $project = $this->project(trial: true);
        $project->update(['onboarding' => ['article_automation_started_at' => now()->toIso8601String()]]);
        $ids = $this->ideas(8);
        $run = PipelineRun::factory()->create(['pipeline' => 'planning']);
        $context = new StepContext($run, $project, ['month' => '2026-09-01'], [
            SelectTopics::key() => (new SelectionPayload($ids))->toArray(),
            TypeAndFlagUnits::key() => (new TypingPayload([], []))->toArray(),
        ], [], new FakeModelGateway);
        app(ScheduleCalendar::class)->handle($context);
        $items = ContentPlan::query()->firstOrFail()->contentItems()->get();
        $this->assertCount(3, $items);
        $this->assertSame(3, $items->pluck('scheduled_for')->unique()->count());
        $this->assertSame(3, ArticleSchedule::query()->count());
        $this->assertSame(['09:00'], ArticleSchedule::query()->pluck('local_time')->unique()->all());
        $this->assertSame(['blocked'], ArticleSchedule::query()->pluck('status')->unique()->all());
        $this->assertSame(1, app(Entitlements::class)->for($project)->used(Metric::ContentPlans));
        $this->assertSame(0, ArticleWorkflow::capacity($project));
    }

    public function test_old_project_false_preference_remains_unchanged(): void
    {
        $project = $this->project();
        $project->update(['autopublish' => false, 'onboarded_at' => now()->subMonth()]);
        app(ProjectLaunch::class)->begin($project);
        $this->assertFalse($project->refresh()->autopublish);
        $this->assertArrayNotHasKey('article_automation_started_at', $project->onboarding);
    }

    public function test_lapsed_subscription_stops_already_queued_generation_before_a_step_runs(): void
    {
        $project = $this->project();
        $item = ContentItem::factory()->create(['type' => ContentItemType::Explainer]);
        $run = app(PipelineRunner::class)->start('generation', $project, [], $item->id);
        ProjectSubscription::query()->where('project_id', $project->id)->update(['status' => BillingStatus::Canceled]);
        app(PipelineRunner::class)->execute($run, 'compile_brief');
        $this->assertSame(PipelineRunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame(0, $run->steps()->sum('input_tokens'));
        $this->assertSame(ContentItemState::Idea, $item->refresh()->state);
    }

    public function test_settings_allow_automatic_or_review_first_but_never_social(): void
    {
        $project = $this->project();
        $owner = User::factory()->create();
        $project->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->postJson('/onboarding/'.$project->id.'/save', [
            'step' => 'settings', 'answers' => ['weekly_target' => 7, 'autopublish' => false],
        ])->assertOk();
        $this->assertFalse($project->refresh()->autopublish);
        $this->actingAs($owner)->postJson('/onboarding/'.$project->id.'/save', [
            'step' => 'settings', 'answers' => ['autopublish' => true],
        ])->assertOk();
        $this->assertTrue($project->refresh()->autopublish);
        $project->update(['is_ymyl' => true]);
        $this->actingAs($owner)->postJson('/onboarding/'.$project->id.'/save', [
            'step' => 'settings', 'answers' => ['autopublish' => true],
        ])->assertOk();
        $this->assertFalse($project->refresh()->autopublish);
        $this->actingAs($owner)->postJson('/onboarding/'.$project->id.'/save', [
            'step' => 'channels', 'answers' => ['social' => ['threads']],
        ])->assertUnprocessable();
    }

    public function test_pending_manual_drafts_reserve_available_preparation_capacity(): void
    {
        $project = $this->project(trial: true);
        $owner = User::factory()->create();
        $project->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->withSession(['project_id' => $project->id]);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/content/articles', ['prompt' => 'A useful cleaning question '.$i])->assertSessionHasNoErrors();
        }
        $this->post('/content/articles', ['prompt' => 'A fourth cleaning question'])->assertSessionHasErrors('prompt');
        $this->assertSame(3, PipelineRun::query()->where('pipeline', 'generation')->count());
        $this->assertSame(3, ContentItem::query()->count());
        $this->assertSame(0, ArticleWorkflow::capacity($project));
    }

    public function test_stale_selection_preserves_existing_calendar_dates_and_does_not_double_schedule(): void
    {
        $project = $this->project();
        $ids = $this->ideas(3);
        $run = PipelineRun::factory()->create(['pipeline' => 'planning']);
        $context = fn (array $selected): StepContext => new StepContext($run, $project, ['month' => '2026-09-01'], [
            SelectTopics::key() => (new SelectionPayload(array_values($selected)))->toArray(),
            TypeAndFlagUnits::key() => (new TypingPayload([], []))->toArray(),
        ], [], new FakeModelGateway);
        app(ScheduleCalendar::class)->handle($context([$ids[0], $ids[1]]));
        $original = ContentItem::query()->findOrFail($ids[0]);
        $date = $original->scheduled_for->toDateString();
        app(ScheduleCalendar::class)->handle($context([$ids[0], $ids[2]]));
        $this->assertSame($date, $original->refresh()->scheduled_for->toDateString());
        $this->assertSame(3, ContentItem::query()->whereNotNull('content_plan_id')->count());
        $this->assertSame(3, ContentItem::query()->whereNotNull('content_plan_id')->distinct()->count('scheduled_for'));
        $this->assertSame(1, app(Entitlements::class)->for($project)->used(Metric::ContentPlans));
    }

    private function project(bool $trial = false): Project
    {
        $project = Project::factory()->onboarding()->create(['autopublish' => true, 'weekly_target' => 7, 'research_seeds' => ['cleaning']]);
        ProjectSubscription::query()->where('project_id', $project->id)->update([
            'plan' => 'growth',
            'status' => $trial ? BillingStatus::Trialing : BillingStatus::Active,
            'trial_ends_at' => $trial ? now()->addDays(3) : null,
            // A trial's billing period is the free window itself, starting now.
            ...($trial ? ['period_started_at' => now(), 'period_ends_at' => now()->addDays(3)] : []),
        ]);
        app(Entitlements::class)->forget($project);
        app(CurrentProject::class)->set($project);

        return $project;
    }

    /** @return list<string> */
    private function ideas(int $count): array
    {
        return array_values(ContentItem::factory()->count($count)->create([
            'type' => ContentItemType::Explainer, 'state' => ContentItemState::Idea,
            'content_plan_id' => null, 'locale' => 'en',
        ])->map(fn (ContentItem $item): string => $item->id)->all());
    }
}
