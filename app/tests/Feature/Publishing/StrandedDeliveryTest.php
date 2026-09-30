<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Console\Commands\PublishSweepStrandedCommand;
use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\Pages\DispatchPageOperation;
use App\Publishing\StrandedDeliveries;
use App\Publishing\WebhookPublisher;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A killed worker must not cost an article (§9).
 *
 * `pending` and `retrying` both promise a job is on its way, and nothing in the
 * engine revisits either when that job is lost: {@see DeliverWebhookJob} has
 * one try, and `dispatch_key` stops `queue()` making a second row. So a restart
 * between the dispatch and the outcome used to leave a delivery pending — or
 * retrying at a time long past — forever: the operator told it was queued, the
 * screen showing nothing wrong, and recovery available only to somebody who
 * already knew to run `publish:replay` against a row nothing surfaced.
 *
 * Two halves, tested here as two: the sweep that recovers it, and the screen
 * that says so before the sweep runs.
 */
final class StrandedDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-09 12:00:00');

        $this->project = Project::factory()->create();
        $this->operator = User::factory()->create();
        $this->operator->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);

        Channel::factory()->create([
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://receiver.test/engine/webhook'],
            'secret' => 'shared-secret',
            'verified_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- the sweep

    #[Test]
    public function a_delivery_stranded_pending_goes_back_into_the_queue(): void
    {
        Queue::fake();

        $delivery = $this->pending(minutesAgo: 75);

        $this->sweep();

        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);
        // On the publishing lane, whose `retry_after` is what makes a
        // four-minute threshold safe — not back on the pipeline's.
        Queue::assertPushed(
            DeliverWebhookJob::class,
            fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->getKey()
                && $job->connection === 'publishing'
                && $job->queue === 'publishing',
        );
    }

    #[Test]
    public function the_sweep_does_not_spend_a_rung_of_the_published_ladder(): void
    {
        Queue::fake();

        $delivery = $this->pending(minutesAgo: 75);

        $this->sweep();

        // §6.2 promises five attempts and this was not one of them. Nor is it
        // a deferral, which is the receiver asking us to wait: it is counted
        // on its own, so neither bound is spent by the other.
        $this->assertSame(0, $delivery->refresh()->attempts);
        $this->assertSame(1, $delivery->sweeps);
        $this->assertSame(0, $delivery->deferrals);
    }

    #[Test]
    public function a_delivery_still_inside_its_window_is_left_alone(): void
    {
        Queue::fake();

        // Three minutes is past the publishing connection's `retry_after`
        // (150 s) but inside the threshold, which leaves a sweep of slack for
        // the queue to fail the job it re-offered. Sweeping earlier could
        // dispatch a second copy of a delivery that is still running — the
        // failure §9 exists to prevent.
        $delivery = $this->pending(minutesAgo: 3);

        $this->sweep();

        $this->assertSame(DeliveryStatus::Pending, $delivery->refresh()->status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_delivery_whose_attempt_is_under_way_is_not_swept_over(): void
    {
        Queue::fake();

        // Overdue by the clock, but a worker has just taken it — a job that sat
        // behind a busy queue can start while the sweep is deciding. The sweep
        // must not rewrite the row under a live attempt, or dispatch a second.
        $delivery = $this->pending(minutesAgo: 75);
        $attempt = Cache::lock('webhook-delivery:'.$delivery->getKey(), WebhookPublisher::lockSeconds());
        $this->assertTrue($attempt->get());

        $this->sweep();

        $this->assertSame(DeliveryStatus::Pending, $delivery->refresh()->status);
        $this->assertSame(0, $delivery->sweeps);
        Queue::assertNothingPushed();

        $attempt->release();
    }

    #[Test]
    public function nothing_is_swept_while_the_publishing_queue_has_jobs_waiting(): void
    {
        Queue::fake();

        // Overdue by the clock — but the lane has a backlog, or no workers,
        // and this row's job may be one of those waiting. Age cannot tell the
        // two apart; an empty queue can. Sweeping here would queue a second
        // copy, and doing it three times would dead-letter an article whose
        // job is still coming.
        $delivery = $this->pending(minutesAgo: 10, attributes: [
            'sweeps' => StrandedDeliveries::MAX_SWEEPS - 1,
        ]);
        DeliverWebhookJob::dispatch('some-other-delivery');

        $this->sweep();

        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertSame(StrandedDeliveries::MAX_SWEEPS - 1, $delivery->sweeps);
        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    #[Test]
    public function a_backlog_does_not_hold_off_a_long_overdue_row_for_ever(): void
    {
        Queue::fake();

        // A lane that never drains would otherwise switch recovery off with no
        // end. Past the patience limit the row is swept regardless — a second
        // job for it is harmless now, and an owner waiting on it is not.
        $longOverdue = $this->pending(minutesAgo: intdiv(PublishSweepStrandedCommand::PATIENCE_SECONDS, 60) + 5);
        $recent = $this->pending(minutesAgo: 10);
        DeliverWebhookJob::dispatch('some-other-delivery');

        $this->sweep();

        $this->assertSame(1, $longOverdue->refresh()->sweeps);
        $this->assertSame(0, $recent->refresh()->sweeps);
        Queue::assertPushed(
            DeliverWebhookJob::class,
            fn (DeliverWebhookJob $job): bool => $job->deliveryId === $longOverdue->getKey(),
        );
    }

    #[Test]
    public function page_operations_waiting_on_the_lane_are_not_a_delivery_backlog(): void
    {
        Queue::fake();

        // Same workers, different queue name: a batch of page work must not
        // read as deliveries still coming, or it would hold recovery off.
        $delivery = $this->pending(minutesAgo: 10);
        DispatchPageOperation::dispatch('some-page-operation');

        $this->sweep();

        $this->assertSame(1, $delivery->refresh()->sweeps);
    }

    #[Test]
    public function an_answered_attempt_starts_the_sweep_count_again(): void
    {
        Queue::fake();
        Http::fake(['receiver.test/*' => Http::response([], 503)]);

        // The limit is for a delivery that keeps losing its worker. Once a
        // worker got as far as an answer, earlier losses are not part of the
        // same run.
        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'content_item_id' => ContentItem::factory()->published()->create()->getKey(),
            'sweeps' => StrandedDeliveries::MAX_SWEEPS - 1,
        ]);

        $this->sweep();
        $this->assertSame(StrandedDeliveries::MAX_SWEEPS, $delivery->refresh()->sweeps);

        $this->runJobFor($delivery);

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertSame(0, $delivery->sweeps);
    }

    #[Test]
    public function the_original_job_arriving_after_a_sweep_sends_once(): void
    {
        Queue::fake();
        Http::fake(['receiver.test/*' => Http::response([])]);

        // The sweep decided the row was lost, and then the original job turned
        // up after all. Two jobs, one delivery: the first sends it, the second
        // finds it settled.
        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'content_item_id' => ContentItem::factory()->published()->create()->getKey(),
        ]);

        $this->sweep();
        $this->runJobFor($delivery);
        $this->runJobFor($delivery);

        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_original_job_arriving_after_a_sweep_does_not_skip_a_rung(): void
    {
        Queue::fake();
        Http::fake(['receiver.test/*' => Http::response([], 503)]);

        // The same, when the receiver is down. The first job to run takes the
        // attempt and schedules the next rung; the second must leave that rung
        // to the job scheduled for it rather than spend it a minute early.
        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'content_item_id' => ContentItem::factory()->published()->create()->getKey(),
        ]);

        $this->sweep();
        $this->runJobFor($delivery);
        $scheduled = $delivery->refresh()->next_attempt_at;
        $this->runJobFor($delivery);

        $delivery->refresh();

        Http::assertSentCount(1);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertTrue($scheduled !== null && $delivery->next_attempt_at?->equalTo($scheduled));
    }

    #[Test]
    public function a_delivery_stranded_every_time_becomes_a_dead_letter(): void
    {
        Queue::fake();

        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'sweeps' => StrandedDeliveries::MAX_SWEEPS,
        ]);

        $this->sweep();

        $delivery->refresh();

        // Not swept forever. A worker being killed every half hour is a
        // problem for a person, and `dead_letter` is the status §7's log sorts
        // to the top and puts a button beside.
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        // Swept MAX_SWEEPS times and found lost once more: that is how many
        // interruptions the owner is told about.
        $this->assertStringContainsString(
            sprintf('interrupted %d times in a row', StrandedDeliveries::MAX_SWEEPS + 1),
            (string) $delivery->error,
        );
        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------ a lost retry

    #[Test]
    public function a_retrying_delivery_whose_job_was_lost_goes_back_into_the_queue(): void
    {
        Queue::fake();

        // Refused once, a retry scheduled for ten minutes ago, and nothing has
        // touched it since: the delayed job went with the worker that held it.
        // This row used to be recovered by nothing at all.
        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'status' => DeliveryStatus::Retrying,
            'attempts' => 1,
            'next_attempt_at' => now()->subMinutes(10),
        ]);

        $this->sweep();

        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertTrue($delivery->next_attempt_at->equalTo(now()));
        // A deferral, not a rung: the ladder still has the attempts it had.
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(1, $delivery->sweeps);
        Queue::assertPushed(
            DeliverWebhookJob::class,
            fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->getKey(),
        );
    }

    #[Test]
    public function a_retrying_delivery_waiting_for_its_turn_is_left_alone(): void
    {
        Queue::fake();

        // Old, but not overdue: one is waiting for its rung of the ladder and
        // the other came due two minutes ago, which is a queue being a queue.
        // Age is measured from when the row was due, never from when it was
        // made — a retry twelve hours up the ladder is not abandoned.
        $waiting = $this->pending(minutesAgo: 75, attributes: [
            'status' => DeliveryStatus::Retrying,
            'attempts' => 2,
            'next_attempt_at' => now()->addMinutes(25),
        ]);
        $due = $this->pending(minutesAgo: 75, attributes: [
            'status' => DeliveryStatus::Retrying,
            'attempts' => 1,
            'next_attempt_at' => now()->subMinutes(2),
        ]);

        $this->sweep();

        $this->assertSame(0, $waiting->refresh()->sweeps);
        $this->assertSame(0, $due->refresh()->sweeps);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_retry_lost_every_time_becomes_a_dead_letter_too(): void
    {
        Queue::fake();

        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'status' => DeliveryStatus::Retrying,
            'attempts' => 1,
            'sweeps' => StrandedDeliveries::MAX_SWEEPS,
            'next_attempt_at' => now()->subMinutes(10),
        ]);

        $this->sweep();

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_pending_row_replayed_in_place_ages_from_its_replay(): void
    {
        // An article delivery replayed from a dead letter keeps its row, and so
        // its week-old `created_at`. Aged on that, the sweep would dispatch it
        // a second time within the minute.
        $replayed = $this->pending(minutesAgo: 60 * 24 * 7, attributes: ['next_attempt_at' => now()]);

        $this->assertFalse(StrandedDeliveries::includes($replayed));
        $this->assertSame(0, StrandedDeliveries::scope(WebhookDelivery::query())->count());
    }

    // ----------------------------------------------------- what it says

    #[Test]
    public function the_sweep_says_nothing_was_sent_when_the_row_proves_it(): void
    {
        Queue::fake();

        // An article delivery stamps its attempt before the request goes out,
        // so an empty stamp means the worker went away before sending.
        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'article_schedule_id' => $this->articleSchedule()->getKey(),
            'article_attempt_started_at' => null,
        ]);

        $this->sweep();

        $this->assertStringContainsString('Nothing was sent', (string) $delivery->refresh()->error);
    }

    #[Test]
    public function the_sweep_does_not_claim_nothing_was_sent_once_an_attempt_had_started(): void
    {
        Queue::fake();

        // The incident this is written against, in the other order: the
        // worker got as far as stamping the attempt and then went away. The
        // request may have arrived. Saying "nothing was sent" would be a guess
        // presented as a fact, to the person deciding whether to worry.
        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'article_schedule_id' => $this->articleSchedule()->getKey(),
            'article_attempt_started_at' => now()->subMinutes(74),
        ]);
        $deliveryId = $delivery->delivery_id;

        $this->sweep();

        $delivery->refresh();

        $this->assertStringNotContainsString('Nothing was sent', (string) $delivery->error);
        $this->assertStringContainsString('It may have reached the website', (string) $delivery->error);
        $this->assertStringContainsString('same delivery id', (string) $delivery->error);

        // And the message is true: the row is re-sent, not replaced, so the
        // receiver sees the id it may already have and can dedupe on it.
        $this->assertSame($deliveryId, $delivery->delivery_id);
        $this->assertSame(1, WebhookDelivery::query()->count());
        Queue::assertPushed(
            DeliverWebhookJob::class,
            fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->getKey(),
        );
    }

    #[Test]
    public function a_delivery_that_records_nothing_before_sending_is_not_called_unsent(): void
    {
        Queue::fake();

        // No article stamp to read, so the row cannot tell a worker that
        // never started from one that stopped mid-request.
        $delivery = $this->pending(minutesAgo: 75);

        $this->sweep();

        $this->assertStringContainsString('It may have reached the website', (string) $delivery->refresh()->error);
    }

    #[Test]
    public function a_sweep_with_nothing_to_do_is_a_success(): void
    {
        Queue::fake();

        $this->sweep();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_sweep_reaches_every_project(): void
    {
        Queue::fake();

        $mine = $this->pending(minutesAgo: 75);

        $other = Project::factory()->create();
        $theirs = app(CurrentProject::class)->run($other, function (): WebhookDelivery {
            Channel::factory()->create([
                'type' => ChannelType::Webhook,
                'config' => ['endpoint' => 'https://other.test/engine/webhook'],
                'secret' => 'other-secret',
            ]);

            return $this->pending(minutesAgo: 75);
        });

        // A scheduled sweep has no operator and no tenant in context. The row
        // names its project; the dispatch runs inside it.
        $this->sweep();

        $this->assertSame(DeliveryStatus::Retrying, $mine->refresh()->status);
        /** @var WebhookDelivery $swept */
        $swept = WebhookDelivery::acrossProjects()->findOrFail($theirs->getKey());

        $this->assertSame(DeliveryStatus::Retrying, $swept->status);
    }

    // ------------------------------------------------------------ the screen

    #[Test]
    public function the_delivery_log_flags_a_stranded_row(): void
    {
        $this->pending(minutesAgo: 75);

        $this->actingAs($this->operator)
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('deliveries/index')
                ->where('stranded', 1)
                ->where('deliveries.data.0.is_stranded', true)
            );
    }

    #[Test]
    public function a_pending_row_that_is_merely_young_is_not_flagged(): void
    {
        $this->pending(minutesAgo: 2);

        $this->actingAs($this->operator)
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stranded', 0)
                ->where('deliveries.data.0.is_stranded', false)
            );
    }

    #[Test]
    public function the_delivery_log_flags_a_lost_retry_and_not_a_waiting_one(): void
    {
        $this->pending(minutesAgo: 75, attributes: [
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->subMinutes(10),
        ]);
        $this->pending(minutesAgo: 70, attributes: [
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->addMinutes(20),
        ]);

        $this->actingAs($this->operator)
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stranded', 1)
                // Sorted to the top, above the newer row that is only waiting.
                ->where('deliveries.data.0.is_stranded', true)
                ->where('deliveries.data.1.is_stranded', false)
            );
    }

    #[Test]
    public function a_stranded_row_sorts_with_the_dead_letters(): void
    {
        $stranded = $this->pending(minutesAgo: 75);
        $this->pending(minutesAgo: 1, attributes: ['status' => DeliveryStatus::Delivered]);
        $this->pending(minutesAgo: 0, attributes: ['status' => DeliveryStatus::Delivered]);

        $this->actingAs($this->operator)
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Above two newer rows, which `latest()` alone would put first.
                ->where('deliveries.data.0.id', $stranded->getKey())
            );
    }

    // ------------------------------------------------------------- the guard

    #[Test]
    public function a_pending_delivery_cannot_be_replayed(): void
    {
        $delivery = $this->pending(minutesAgo: 75);

        // The screen offers the button only on a dead letter, and the screen is
        // not the guard. Replaying an in-flight delivery is how the same post
        // goes out twice.
        $this->actingAs($this->operator)
            ->post(route('deliveries.replay', $delivery))
            ->assertStatus(409);

        $this->assertSame(1, WebhookDelivery::query()->count());
    }

    #[Test]
    public function a_retrying_delivery_cannot_be_replayed_either(): void
    {
        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->addMinutes(5),
        ]);

        $this->actingAs($this->operator)
            ->post(route('deliveries.replay', $delivery))
            ->assertStatus(409);

        $this->assertSame(1, WebhookDelivery::query()->count());
    }

    #[Test]
    public function a_dead_letter_is_still_replayable(): void
    {
        $delivery = $this->pending(minutesAgo: 75, attributes: [
            'status' => DeliveryStatus::DeadLetter,
            'error' => 'The receiver refused it five times.',
        ]);

        $this->actingAs($this->operator)
            ->post(route('deliveries.replay', $delivery))
            ->assertRedirect();

        $this->assertSame(2, WebhookDelivery::query()->count());
    }

    /**
     * `artisan()` is typed `PendingCommand|int` and `assertSuccessful()` only
     * records the expectation — the command runs in `__destruct()`.
     */
    private function sweep(): void
    {
        /** @var PendingCommand $pending */
        $pending = $this->artisan('publish:sweep-stranded');

        $pending->assertSuccessful()->run();
    }

    /** One of the (possibly several) jobs for this row, run the way a worker would. */
    private function runJobFor(WebhookDelivery $delivery): void
    {
        (new DeliverWebhookJob($delivery->getKey()))->handle(
            app(ChannelPublisherRegistry::class),
            app(CurrentProject::class),
        );
    }

    /** The schedule an article delivery belongs to, which is what stamps its attempts. */
    private function articleSchedule(): ArticleSchedule
    {
        $item = ContentItem::factory()->create();

        return ArticleSchedule::query()->create([
            'content_item_id' => $item->getKey(),
            'channel_id' => Channel::query()->firstOrFail()->getKey(),
            'publish_at' => now()->subHours(2),
            'local_date' => '2026-08-09',
            'local_time' => '10:00',
            'timezone' => 'UTC',
            'mode' => 'automatic',
            'status' => 'dispatching',
            'origin' => 'engine',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function pending(int $minutesAgo, array $attributes = []): WebhookDelivery
    {
        $at = now()->subMinutes($minutesAgo);

        $delivery = WebhookDelivery::factory()->create([
            'channel_id' => Channel::query()->firstOrFail()->getKey(),
            'content_item_id' => ContentItem::factory()->create()->getKey(),
            'status' => DeliveryStatus::Pending,
            'attempts' => 0,
            'deferrals' => 0,
            'sweeps' => 0,
            'next_attempt_at' => null,
            ...$attributes,
        ]);

        // `created_at` is what ages a pending row with no `next_attempt_at` —
        // see StrandedDeliveries — and the factory stamps it with now().
        $delivery->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $delivery->refresh();
    }
}
