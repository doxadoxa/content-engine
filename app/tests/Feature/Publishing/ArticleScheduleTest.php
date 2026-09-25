<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Content\ArticleScore;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Publishing\Articles\ArticleApproval;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\PublishToChannels;
use App\Publishing\WebhookPublisher;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\ValidationException;
use Mockery\Expectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ArticleScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Channel $channel;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T08:00:00Z'));
        Queue::fake();
        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $this->channel = Channel::factory()->create(['type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://receiver.test/articles'], 'is_enabled' => true,
            'verified_at' => now(), 'autopublish' => true]);
        $this->item = ContentItem::factory()->draft()->create(['body_html' => '<p>A useful article.</p>', 'factcheck' => ['passed' => true]]);
        $this->mock(ArticleScore::class, function (MockInterface $mock): void {
            /** @var Expectation $expectation */
            $expectation = $mock->shouldReceive('for');
            $expectation->andReturn(['score' => 100, 'publishable' => true, 'blocking' => [], 'checks' => []]);
        });
    }

    #[Test]
    public function automatic_schedule_waits_for_real_local_time_then_approves_and_queues_once(): void
    {
        $schedule = $this->schedule();
        $this->assertSame('2026-09-15T09:00:00+00:00', $schedule->publish_at->toIso8601String());
        $this->assertNull($this->item->scheduled_for);
        $this->assertSame([], app(PublishToChannels::class)->publishAutomatically($this->item));
        $this->assertSame(ContentItemState::Draft, $this->item->fresh()->state);
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $first = app(ArticleSchedules::class)->dispatch($this->item);
        $this->assertCount(1, $first);
        $this->assertSame($schedule->id, $first[0]->article_schedule_id);
        $this->assertSame(ContentItemState::Approved, $this->item->fresh()->state);
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($this->item));
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        $this->assertSame(1, DB::table('article_approval_records')->where('units', 1)->count());
    }

    #[Test]
    public function review_first_never_approves_itself_and_explicit_approval_uses_the_same_schedule(): void
    {
        $this->schedule('review_first');
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($this->item));
        $this->assertSame(ContentItemState::Draft, $this->item->fresh()->state);
        $this->assertStringContainsString('Review', ArticleSchedule::query()->firstOrFail()->blocked_reason);
        app(ArticleApproval::class)->approve($this->item);
        $this->assertCount(1, app(ArticleSchedules::class)->dispatch($this->item));
    }

    #[Test]
    public function approval_without_a_schedule_waits_for_an_explicit_publish_even_on_an_automatic_channel(): void
    {
        Http::fake();

        $this->actingAs($this->owner)->post("/content/{$this->item->id}/approve")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ContentItemState::Approved, $this->item->fresh()->state);
        $this->assertDatabaseCount('webhook_deliveries', 0);
        Queue::assertNothingPushed();

        /** @var PendingCommand $command */
        $command = $this->artisan('publish:approved', ['project' => $this->project->slug]);
        $command->assertSuccessful()->run();
        $this->assertDatabaseCount('webhook_deliveries', 0);
        Queue::assertNothingPushed();

        $this->actingAs($this->owner)->post("/content/{$this->item->id}/publish")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('webhook_deliveries', 1);
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        Http::assertNothingSent();
    }

    #[Test]
    public function revisions_and_repeated_approval_consume_one_article_in_total(): void
    {
        app(ArticleApproval::class)->approve($this->item);
        app(ArticleApproval::class)->approve($this->item);
        $this->item->returnForRework();
        app(ArticleApproval::class)->approve($this->item);
        $this->assertSame(1, (int) DB::table('article_approval_records')->sum('units'));
        $this->assertSame(1, (int) DB::table('project_usage_periods')->where('metric', Metric::Articles->value)->sum('used'));
    }

    #[Test]
    public function quota_failures_leave_the_article_unapproved_and_unsent(): void
    {
        app(Entitlements::class)->record($this->project, Metric::Articles, 1000);
        $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($this->item));
        $this->assertSame(ContentItemState::Draft, $this->item->fresh()->state);
        $this->assertSame(0, DB::table('article_approval_records')->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function ymyl_cannot_be_automatically_approved(): void
    {
        $this->project->update(['is_ymyl' => true]);
        $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($this->item));
        $this->assertSame(ContentItemState::Draft, $this->item->fresh()->state);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function real_quality_checks_block_an_unfinished_article_without_charging(): void
    {
        $this->app->forgetInstance(ArticleScore::class);
        $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($this->item));
        $this->assertSame(ContentItemState::Draft, $this->item->fresh()->state);
        $this->assertSame(0, DB::table('article_approval_records')->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function failed_or_missing_factcheck_prevents_automatic_approval_even_with_a_high_score(): void
    {
        $this->item->update(['factcheck' => ['passed' => false]]);
        $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($this->item));
        $this->assertSame(ContentItemState::Draft, $this->item->fresh()->state);
        $this->assertSame(0, DB::table('article_approval_records')->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function failed_factcheck_or_channel_stop_after_queueing_prevents_the_worker_request(): void
    {
        $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $delivery = app(ArticleSchedules::class)->dispatch($this->item)[0];
        $this->item->update(['factcheck' => ['passed' => false]]);
        Http::fake();
        app(WebhookPublisher::class)->attempt($delivery);
        Http::assertNothingSent();
        $this->assertNull($delivery->fresh()->article_attempt_started_at);
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->fresh()->status);
    }

    #[Test]
    public function article_changes_after_queueing_never_send_the_stale_snapshot(): void
    {
        $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $delivery = app(ArticleSchedules::class)->dispatch($this->item)[0];
        $this->item->update(['summary' => 'The corrected summary.']);
        Http::fake();
        app(WebhookPublisher::class)->attempt($delivery);
        Http::assertNothingSent();
        $this->assertNull($delivery->fresh()->article_attempt_started_at);
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->fresh()->status);
    }

    #[Test]
    public function a_changed_faq_cannot_publish_the_older_queued_claims(): void
    {
        $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $delivery = app(ArticleSchedules::class)->dispatch($this->item)[0];
        $this->item->update(['faq_json_ld' => ['description' => 'The corrected service inclusion.']]);
        Http::fake();
        app(WebhookPublisher::class)->attempt($delivery);
        Http::assertNothingSent();
        $this->assertNull($delivery->fresh()->article_attempt_started_at);
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->fresh()->status);
    }

    #[Test]
    public function channel_automatic_stop_applies_to_explicit_manager_schedules(): void
    {
        $this->schedule();
        $this->channel->update(['autopublish' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($this->item));
        $this->assertSame('blocked', ArticleSchedule::query()->firstOrFail()->status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function historical_approved_articles_and_old_auto_flags_do_not_create_deliveries(): void
    {
        $this->project->update(['autopublish' => true]);
        $this->item->forceFill(['state' => ContentItemState::Approved])->save();
        $this->assertNull(app(ArticleSchedules::class)->scheduleNew($this->item, now()->addDay()));
        /** @var PendingCommand $command */
        $command = $this->artisan('publish:approved', ['project' => $this->project->id]);
        $command->assertSuccessful();
        $command->run();
        Queue::assertNothingPushed();
        $this->assertSame(0, ArticleSchedule::query()->count());
    }

    #[Test]
    public function new_opted_in_articles_keep_a_visible_blocked_schedule_without_a_verified_target(): void
    {
        $this->project->update(['autopublish' => true, 'onboarding' => ['article_automation_started_at' => now()->toIso8601String()]]);
        $this->channel->update(['verified_at' => null]);
        $new = ContentItem::factory()->create();
        $schedule = app(ArticleSchedules::class)->scheduleNew($new, now()->addDay());
        $this->assertNotNull($schedule);
        $this->assertSame('blocked', $schedule->status);
        $this->assertNull($schedule->channel_id);
    }

    #[Test]
    public function later_website_connection_resolves_only_inherited_unassigned_schedules_without_sending_early(): void
    {
        $this->project->update(['autopublish' => true, 'onboarding' => ['article_automation_started_at' => now()->toIso8601String()]]);
        $this->channel->update(['verified_at' => null]);
        $new = ContentItem::factory()->create();
        $schedule = app(ArticleSchedules::class)->scheduleNew($new, now()->addDay());
        $this->assertNotNull($schedule);
        $this->assertNull($schedule->channel_id);
        $this->channel->update(['verified_at' => now()]);
        app(ArticleSchedules::class)->resolveTargets($this->project);
        $this->assertSame($this->channel->id, $schedule->fresh()->channel_id);
        $this->assertSame('active', $schedule->fresh()->status);
        $this->assertSame(2, $schedule->fresh()->version);
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($new));
        $this->assertSame(1, ArticleSchedule::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function new_review_first_projects_inherit_real_times_without_automatic_approval(): void
    {
        $this->project->update(['autopublish' => false, 'onboarding' => ['article_automation_started_at' => now()->toIso8601String()]]);
        $this->channel->update(['autopublish' => false]);
        $new = ContentItem::factory()->draft()->create();
        $schedule = app(ArticleSchedules::class)->scheduleNew($new, now()->addHour());
        $this->assertNotNull($schedule);
        $this->assertSame('review_first', $schedule->mode);
        $this->assertSame('active', $schedule->status);
        $this->travel(1)->hours();
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($new));
        app(ArticleApproval::class)->approve($new);
        $this->assertCount(1, app(ArticleSchedules::class)->dispatch($new));
    }

    #[Test]
    public function displayed_wall_time_follows_project_timezone_without_changing_publication_instant(): void
    {
        $schedule = $this->schedule();
        $this->project->update(['timezone' => 'America/Los_Angeles']);
        $props = app(ArticleSchedules::class)->props($this->item->fresh());
        $this->assertSame('02:00', $props['schedule']['local_time']);
        $this->assertSame('America/Los_Angeles', $props['schedule']['timezone']);
        $this->assertSame('2026-09-15T09:00:00+00:00', $schedule->fresh()->publish_at->toIso8601String());
    }

    #[Test]
    public function canceling_a_queued_schedule_stops_a_direct_worker_and_reschedule_uses_a_new_version(): void
    {
        $schedule = $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $delivery = app(ArticleSchedules::class)->dispatch($this->item)[0];
        app(ArticleSchedules::class)->change($this->item, $schedule->version, 'cancel');
        Http::fake();
        app(WebhookPublisher::class)->attempt($delivery);
        Http::assertNothingSent();
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->fresh()->status);
        $replacement = $this->schedule(expected: 2, time: '11:00');
        $this->assertSame(3, $replacement->version);
        $this->assertSame([], app(ArticleSchedules::class)->dispatch($this->item));
    }

    #[Test]
    public function direct_queue_cannot_bypass_a_future_schedule(): void
    {
        $this->schedule();
        app(ArticleApproval::class)->approve($this->item);
        $delivery = app(WebhookPublisher::class)->queue($this->item, $this->channel);
        Http::fake();
        app(WebhookPublisher::class)->attempt($delivery);
        Http::assertNothingSent();
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->fresh()->status);
    }

    #[Test]
    public function attempted_or_inflight_deliveries_cannot_be_canceled_and_blindly_reissued(): void
    {
        $schedule = $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $delivery = app(ArticleSchedules::class)->dispatch($this->item)[0];
        $delivery->forceFill(['article_attempt_started_at' => now()])->save();
        $this->expectException(ValidationException::class);
        app(ArticleSchedules::class)->change($this->item, $schedule->version, 'cancel');
    }

    #[Test]
    public function delivery_lock_prevents_cancellation_from_racing_a_sender(): void
    {
        $schedule = $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $delivery = app(ArticleSchedules::class)->dispatch($this->item)[0];
        $lock = Cache::lock('webhook-delivery:'.$delivery->id, 30);
        $this->assertTrue($lock->get());
        try {
            app(ArticleSchedules::class)->change($this->item, $schedule->version, 'cancel');
            $this->fail('An in-flight delivery must retain its authorization until the sender finishes.');
        } catch (ValidationException) {
            $this->assertSame('dispatching', $schedule->fresh()->status);
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function completed_delivery_marks_the_schedule_and_keeps_the_published_url(): void
    {
        $schedule = $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T09:00:00Z'));
        $delivery = app(ArticleSchedules::class)->dispatch($this->item)[0];
        Http::fake(['receiver.test/*' => Http::response(['public_url' => 'https://example.test/article'])]);
        app(WebhookPublisher::class)->attempt($delivery);
        $this->assertSame('completed', $schedule->fresh()->status);
        $this->assertSame(ContentItemState::Published, $this->item->fresh()->state);
        $this->assertNotNull($delivery->fresh()->article_attempt_started_at);
        Http::assertSentCount(1);
    }

    #[Test]
    public function word_press_native_page_verification_is_not_article_verification(): void
    {
        $this->channel->update(['type' => ChannelType::WordPress, 'config' => ['page_receiver_base' => 'https://receiver.test/wp-json/avyo/v1']]);
        $this->assertFalse(app(ArticleSchedules::class)->compatible($this->channel));
        $this->channel->update(['config' => [...$this->channel->config, 'article_publishing_verified' => true]]);
        $this->assertTrue(app(ArticleSchedules::class)->compatible($this->channel));
    }

    #[Test]
    public function dst_gaps_and_duplicate_wall_times_are_rejected(): void
    {
        foreach ([['2026-03-29', '01:30'], ['2026-10-25', '01:30']] as [$date, $time]) {
            try {
                app(ArticleSchedules::class)->localTime($date, $time, 'Europe/Lisbon');
                $this->fail('Ambiguous or missing local time must not silently move.');
            } catch (ValidationException $error) {
                $this->assertStringContainsString('clocks change', $error->getMessage());
            }
        }
        $this->assertSame('2026-10-25T02:30:00+00:00', app(ArticleSchedules::class)->localTime('2026-10-25', '02:30', 'Europe/Lisbon')->toIso8601String());
    }

    #[Test]
    public function owner_schedule_routes_enforce_versions_and_project_membership(): void
    {
        $this->actingAs($this->owner)->withSession(['current_project_id' => $this->project->id]);
        $this->put('/content/'.$this->item->id.'/schedule', $this->input())->assertRedirect();
        $this->put('/content/'.$this->item->id.'/schedule', $this->input())->assertConflict();
        $member = User::factory()->create();
        $member->projects()->attach($this->project, ['role' => 'operator']);
        $this->actingAs($member)->delete('/content/'.$this->item->id.'/schedule', ['expected_version' => 1])->assertForbidden();
    }

    private function schedule(string $mode = 'automatic', ?int $expected = null, string $time = '10:00'): ArticleSchedule
    {
        return app(ArticleSchedules::class)->save($this->owner, $this->item, $this->input($mode, $expected, $time));
    }

    /** @return array{expected_version: int|null, local_date: string, local_time: string, mode: string, channel_id: string} */
    private function input(string $mode = 'automatic', ?int $expected = null, string $time = '10:00'): array
    {
        return ['expected_version' => $expected, 'local_date' => '2026-09-15', 'local_time' => $time, 'mode' => $mode, 'channel_id' => $this->channel->id];
    }
}
