<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\FakeModelGateway;
use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Content\UnitScore;
use App\Enums\BillingStatus;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PipelineRunStatus;
use App\Models\ArticlePlanningPeriod;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Steps\Planning\PlanningWindow;
use App\Pipelines\Steps\Planning\ScheduleCalendar;
use App\Pipelines\Steps\Planning\SelectionPayload;
use App\Pipelines\Steps\Planning\SelectTopics;
use App\Pipelines\Steps\Planning\TypeAndFlagUnits;
use App\Pipelines\Steps\Planning\TypingPayload;
use App\Publishing\Articles\ArticleApproval;
use App\Publishing\Articles\ArticleDeliveryGuard;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\WebhookPublisher;
use App\Support\Engine\ArticleWorkflow;
use App\Support\Engine\MonthPlanner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BillingPeriodCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['social.enabled' => false]);
    }

    /** @return array<string, array{string, string}> */
    public static function periods(): array
    {
        return [
            '28 days' => ['2027-01-31T19:00:00Z', '2027-02-28T19:00:00Z'],
            '29 leap-year days' => ['2028-01-31T19:00:00Z', '2028-02-29T19:00:00Z'],
            '30 days' => ['2026-04-29T19:00:00Z', '2026-05-29T19:00:00Z'],
            '31 days with DST' => ['2026-10-15T19:00:00Z', '2026-11-15T19:00:00Z'],
        ];
    }

    #[DataProvider('periods')]
    public function test_growth_has_thirty_spaced_slots_inside_the_real_period(string $start, string $end): void
    {
        $project = $this->project('growth', $start, $end, 'America/Los_Angeles');
        $window = PlanningWindow::forProject($project);
        $slots = $window->publicationSlots();
        $this->assertCount(30, $slots);
        $this->assertCount(30, array_unique(array_map(fn (Carbon $slot): int => $slot->getTimestamp(), $slots)));
        foreach ($slots as $slot) {
            $this->assertTrue($slot->greaterThan(Carbon::parse($start)) && $slot->lessThan(Carbon::parse($end)));
            $this->assertContains($slot->format('H:i'), ['09:00', '15:00']);
        }
        $daily = array_count_values(array_map(fn (Carbon $slot): string => $slot->toDateString(), $slots));
        $this->assertLessThanOrEqual(2, max([0, ...array_values($daily)]));
        $this->assertGreaterThan($window->month->toDateString(), $slots[29]->copy()->startOfMonth()->toDateString());
    }

    public function test_late_month_starter_keeps_all_twelve_and_replanning_is_idempotent(): void
    {
        $project = $this->project();
        $ids = $this->ideas(25);
        $this->schedule($project, array_slice($ids, 0, 5));
        $original = ContentItem::query()->whereNotNull('planned_publication_at')->pluck('planned_publication_at', 'id')->map(fn ($at): string => (string) $at)->all();
        $this->schedule($project, $ids);
        $this->assertSame(12, ContentItem::query()->whereNotNull('article_planning_period_id')->count());
        $this->assertSame(12, ArticleSchedule::query()->count());
        foreach ($original as $id => $at) {
            $this->assertSame($at, (string) ContentItem::query()->findOrFail($id)->planned_publication_at);
        }
        $this->assertSame(1, ArticlePlanningPeriod::query()->count());
        $this->assertSame(1, app(Entitlements::class)->for($project)->used(Metric::ContentPlans));
        $this->assertSame(0, ArticleWorkflow::capacity($project));
        $this->assertTrue(ContentItem::query()->where('scheduled_for', '>=', '2026-10-01')->exists());
        $this->schedule($project, []);
        $this->assertSame(12, ArticleSchedule::query()->count());
    }

    public function test_upgrade_adds_only_extra_capacity_and_keeps_existing_dates(): void
    {
        $project = $this->project();
        $ids = $this->ideas(40);
        $this->schedule($project, $ids);
        $old = ArticleSchedule::query()->pluck('publish_at', 'id')->map(fn ($at): string => (string) $at)->all();
        ProjectSubscription::query()->where('project_id', $project->id)->update(['plan' => 'growth']);
        app(Entitlements::class)->forget($project);
        $run = app(MonthPlanner::class)->start($project);
        $this->assertSame('planning', $run->pipeline);
        $this->schedule($project, $ids);
        $this->assertSame(30, ArticleSchedule::query()->count());
        $this->assertSame(1, app(Entitlements::class)->for($project)->used(Metric::ContentPlans));
        foreach ($old as $id => $at) {
            $this->assertSame($at, (string) ArticleSchedule::query()->findOrFail($id)->publish_at);
        }
    }

    public function test_carryover_and_manual_drafts_reserve_current_preparation_capacity(): void
    {
        $project = $this->project();
        ContentItem::factory()->draft()->count(4)->create();
        $this->schedule($project, $this->ideas(20));
        $this->assertSame(8, ArticleSchedule::query()->count());
        $this->assertSame(0, ArticleWorkflow::capacity($project));
    }

    public function test_trial_and_paid_period_in_same_calendar_month_have_separate_plan_reservations(): void
    {
        $project = $this->project('starter', '2026-09-10T12:00:00Z', '2026-09-13T12:00:00Z');
        ProjectSubscription::query()->where('project_id', $project->id)->update(['status' => BillingStatus::Trialing, 'trial_ends_at' => '2026-09-13 12:00:00']);
        app(Entitlements::class)->forget($project);
        $this->schedule($project, $this->ideas(20));
        $this->assertSame(3, ArticleSchedule::query()->count());
        $this->travelTo(Carbon::parse('2026-09-13T12:00:00Z'));
        ProjectSubscription::query()->where('project_id', $project->id)->update(['status' => BillingStatus::Active,
            'period_started_at' => now(), 'period_ends_at' => now()->addMonth(), 'trial_ends_at' => null]);
        app(Entitlements::class)->forget($project);
        $this->schedule($project, $this->ideas(20));
        $this->assertSame(2, ArticlePlanningPeriod::query()->count());
        $this->assertSame(1, ContentPlan::query()->count());
        $this->assertSame(1, app(Entitlements::class)->for($project)->used(Metric::ContentPlans));
        $this->assertSame(2, (int) DB::table('project_usage_periods')->where('metric', 'content_plans')->sum('used'));
        $this->assertSame(12, ArticleSchedule::query()->count());
    }

    public function test_renewal_cannot_reinterpret_an_old_queued_plan_or_pay_its_next_step(): void
    {
        $project = $this->project();
        $run = app(PipelineRunner::class)->start('planning', $project);
        $this->assertArrayHasKey('article_period_started_at', $run->input);
        $this->travelTo(Carbon::parse('2026-10-29T12:00:00Z'));
        ProjectSubscription::query()->where('project_id', $project->id)->update(['period_started_at' => now(), 'period_ends_at' => now()->addMonth()]);
        app(Entitlements::class)->forget($project);
        app(PipelineRunner::class)->execute($run, 'gather_ideas');
        $this->assertSame(PipelineRunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame(0, (int) $run->steps()->sum('cost_micros'));
        $this->assertDatabaseCount('article_planning_periods', 0);
    }

    public function test_missing_period_never_invents_a_renewal(): void
    {
        $project = $this->project();
        ProjectSubscription::query()->where('project_id', $project->id)->update(['period_ends_at' => null]);
        $this->expectException(ValidationException::class);
        app(MonthPlanner::class)->start($project);
    }

    public function test_expired_period_never_invents_a_renewal(): void
    {
        $project = $this->project();
        $this->travelTo(Carbon::parse('2026-10-29T12:00:00Z'));
        $this->expectException(ValidationException::class);
        app(MonthPlanner::class)->start($project);
    }

    public function test_engine_does_not_plan_next_calendar_month_or_start_legacy_visibility(): void
    {
        $project = $this->project();
        $this->schedule($project, $this->ideas(20));
        /** @var PendingCommand $command */
        $command = $this->artisan('engine:tick', ['--project' => $project->id]);
        $command->assertSuccessful()->run();
        $this->assertFalse(PipelineRun::query()->whereIn('pipeline', ['planning', 'visibility', 'research'])->exists());
    }

    public function test_elapsed_slots_are_not_compressed_into_remaining_days(): void
    {
        $project = $this->project();
        $before = PlanningWindow::forProject($project)->publicationSlots();
        $this->travelTo(Carbon::parse('2026-10-20T12:00:00Z'));
        $after = PlanningWindow::forProject($project)->publicationSlots();
        $this->assertLessThan(12, count($after));
        $this->assertGreaterThan(0, count($after));
        foreach ($after as $slot) {
            $this->assertContains($slot->toIso8601String(), array_map(fn (Carbon $at): string => $at->toIso8601String(), $before));
        }
    }

    public function test_missed_automatic_date_requires_a_new_date_before_any_approval(): void
    {
        $project = $this->project();
        Channel::factory()->create(['type' => ChannelType::Webhook, 'config' => ['endpoint' => 'https://receiver.test/articles'], 'verified_at' => now(), 'autopublish' => true, 'is_enabled' => true]);
        $this->schedule($project, $this->ideas(1));
        $schedule = ArticleSchedule::query()->firstOrFail();
        $item = $schedule->contentItem;
        $item->forceFill(['state' => ContentItemState::Draft, 'body_markdown' => 'Useful article', 'factcheck' => ['passed' => true]])->save();
        $this->travelTo($schedule->publish_at->addDay());
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($item));
        $this->assertSame('blocked', $schedule->refresh()->status);
        $this->assertSame(0, DB::table('article_approval_records')->count());
        $this->assertStringContainsString('missed', (string) $schedule->blocked_reason);
    }

    public function test_first_approval_is_counted_once_across_retry_and_renewal(): void
    {
        $project = $this->project();
        $this->mock(UnitScore::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('for');
            $expectation->andReturn(['score' => 100, 'publishable' => true, 'blocking' => [], 'checks' => []]);
        });
        $item = ContentItem::factory()->draft()->create();
        app(ArticleApproval::class)->approve($item);
        app(ArticleApproval::class)->approve($item);
        $this->assertSame(1, app(Entitlements::class)->for($project)->used(Metric::Articles));
        $this->travelTo(Carbon::parse('2026-10-29T12:00:00Z'));
        ProjectSubscription::query()->where('project_id', $project->id)->update(['period_started_at' => now(), 'period_ends_at' => now()->addMonth()]);
        $item->forceFill(['state' => ContentItemState::Draft])->save();
        app(ArticleApproval::class)->approve($item);
        $this->assertSame(0, app(Entitlements::class)->for($project)->used(Metric::Articles));
        $this->assertSame(1, DB::table('article_approval_records')->count());
    }

    public function test_deliberately_lower_cadence_preserves_quota_and_default_trial_still_demonstrates_three(): void
    {
        $project = $this->project('growth');
        $project->update(['weekly_target' => 1]);
        $window = PlanningWindow::forProject($project);
        $this->assertSame(30, $window->articleLimit);
        $this->assertCount(4, $window->publicationSlots());
        $this->schedule($project, $this->ideas(40));
        $this->assertSame(4, ArticleSchedule::query()->count());
        $this->assertSame(26, ArticleWorkflow::capacity($project));
        $this->assertSame(0, ArticleWorkflow::calendarCapacity($project, $window));

        $trial = $this->project('starter', '2026-09-10T12:00:00Z', '2026-09-13T12:00:00Z');
        $trial->update(['weekly_target' => 3]);
        ProjectSubscription::query()->where('project_id', $trial->id)->update(['status' => BillingStatus::Trialing, 'trial_ends_at' => '2026-09-13 12:00:00']);
        $this->assertCount(3, PlanningWindow::forProject($trial)->publicationSlots());
        $trial->update(['weekly_target' => 1]);
        $this->assertCount(1, PlanningWindow::forProject($trial)->publicationSlots());
    }

    public function test_worker_blocks_a_missed_unattempted_slot_but_preserves_an_uncertain_receipt(): void
    {
        $project = $this->project();
        $channel = Channel::factory()->create(['type' => ChannelType::Webhook, 'config' => ['endpoint' => 'https://receiver.test/articles'], 'verified_at' => now(), 'autopublish' => true, 'is_enabled' => true]);
        $this->schedule($project, $this->ideas(1));
        $schedule = ArticleSchedule::query()->firstOrFail();
        $item = $schedule->contentItem;
        $item->forceFill(['state' => ContentItemState::Approved, 'body_markdown' => 'Useful article', 'factcheck' => ['passed' => true]])->save();
        $this->travelTo($schedule->publish_at);
        $delivery = app(ArticleSchedules::class)->dispatch($item)[0];
        $this->travelTo($schedule->publish_at->addDay());
        $this->assertStringContainsString('missed', (string) app(ArticleDeliveryGuard::class)->refusal($delivery));
        $delivery->update(['article_attempt_started_at' => $schedule->publish_at]);
        $this->assertNull(app(ArticleDeliveryGuard::class)->refusal($delivery->refresh()));
        $delivery->update(['article_attempt_started_at' => null]);
        Http::fake();
        app(WebhookPublisher::class)->attempt($delivery->refresh());
        Http::assertNothingSent();
        $this->assertSame(0, $delivery->refresh()->attempts);
    }

    public function test_expired_billing_period_stops_queued_generation_before_any_paid_step(): void
    {
        $project = $this->project();
        $item = ContentItem::factory()->create(['type' => ContentItemType::Explainer]);
        $run = app(PipelineRunner::class)->start('generation', $project, [], $item->id);
        $this->travelTo(Carbon::parse('2026-10-29T12:00:00Z'));
        app(PipelineRunner::class)->execute($run, 'compile_brief');
        $this->assertSame(PipelineRunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame(ContentItemState::Idea, $item->refresh()->state);
        $this->assertSame(0, (int) $run->steps()->sum('cost_micros'));
        $this->expectException(ValidationException::class);
        app(PipelineRunner::class)->start('generation', $project, [], $item->id);
    }

    public function test_articles_asked_for_by_hand_take_separate_slots(): void
    {
        $project = $this->project('growth');
        $owner = User::factory()->create();
        $owner->projects()->attach($project, ['role' => 'owner']);
        $this->actingAs($owner)->withSession(['project_id' => $project->id]);

        foreach (['how often to deep clean a rented flat', 'what a move out clean includes'] as $prompt) {
            $this->post('/content/articles', ['prompt' => $prompt])->assertRedirect();
        }

        // Two requests, two instants. Landing both on tomorrow morning is what
        // made `publish:approved` send them out together.
        $at = ArticleSchedule::acrossProjects()->where('project_id', $project->id)
            ->orderBy('publish_at')->pluck('publish_at')->map(fn ($value): string => Carbon::parse($value)->toIso8601String())->all();
        $this->assertCount(2, $at);
        $this->assertNotSame($at[0], $at[1]);
    }

    private function project(string $plan = 'starter', string $start = '2026-09-29T12:00:00Z', string $end = '2026-10-29T12:00:00Z', string $timezone = 'Europe/Lisbon'): Project
    {
        $this->travelTo(Carbon::parse($start));
        $project = Project::factory()->create(['timezone' => $timezone, 'autopublish' => true, 'weekly_target' => 7,
            'onboarding' => ['article_automation_started_at' => now()->toIso8601String()]]);
        ProjectSubscription::query()->where('project_id', $project->id)->update([
            'plan' => $plan, 'plan_version' => 4, 'status' => BillingStatus::Active,
            'period_started_at' => Carbon::parse($start), 'period_ends_at' => Carbon::parse($end),
        ]);
        app(Entitlements::class)->forget($project);
        app(CurrentProject::class)->set($project);

        return $project;
    }

    /** @return list<string> */
    private function ideas(int $count): array
    {
        return array_values(ContentItem::factory()->count($count)->create(['type' => ContentItemType::Explainer, 'locale' => 'en'])->modelKeys());
    }

    /** @param list<string> $ids */
    private function schedule(Project $project, array $ids): void
    {
        $window = PlanningWindow::forProject($project);
        $run = PipelineRun::factory()->create(['pipeline' => 'test_calendar_fixture']);
        $context = new StepContext($run, $project, $window->input(), [
            SelectTopics::key() => (new SelectionPayload($ids))->toArray(),
            TypeAndFlagUnits::key() => (new TypingPayload([], []))->toArray(),
        ], [], new FakeModelGateway);
        app(ScheduleCalendar::class)->handle($context);
    }
}
