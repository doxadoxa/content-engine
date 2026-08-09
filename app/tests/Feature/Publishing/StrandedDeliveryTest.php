<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\StrandedDeliveries;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pipelines\SocialListenTest;
use Tests\TestCase;

/**
 * A killed worker must not cost a post (§9).
 *
 * `pending` is the one delivery status nothing in the engine ever revisits:
 * {@see DeliverWebhookJob} has one try, the re-dispatch path only looks at
 * `retrying`, and `dispatch_key` stops `queue()` making a second row. So a
 * SIGKILL between the dispatch and the first outcome used to leave a delivery
 * pending forever — the operator told it was queued, the screen showing nothing
 * wrong, and recovery available only to somebody who already knew to run
 * `publish:replay` against a row nothing surfaced.
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

        $delivery = $this->pending(minutesAgo: 45);

        $this->sweep();

        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);
        Queue::assertPushed(
            DeliverWebhookJob::class,
            fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->getKey(),
        );
    }

    #[Test]
    public function the_sweep_does_not_spend_a_rung_of_the_published_ladder(): void
    {
        Queue::fake();

        $delivery = $this->pending(minutesAgo: 45);

        $this->sweep();

        // §6.2 promises five attempts and this was not one of them — nothing
        // was ever sent. It spends a deferral instead, which is the same
        // accounting a full publishing window gets.
        $this->assertSame(0, $delivery->refresh()->attempts);
        $this->assertSame(1, $delivery->deferrals);
    }

    #[Test]
    public function a_delivery_still_inside_its_window_is_left_alone(): void
    {
        Queue::fake();

        // Twenty minutes is inside the queue's own `retry_after`, so a worker
        // may simply be slow. Sweeping here would dispatch a second copy of a
        // delivery that is still running, which for Threads is a duplicate root
        // post — the failure §9 exists to prevent.
        $delivery = $this->pending(minutesAgo: 20);

        $this->sweep();

        $this->assertSame(DeliveryStatus::Pending, $delivery->refresh()->status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_delivery_stranded_every_time_becomes_a_dead_letter(): void
    {
        Queue::fake();

        $delivery = $this->pending(minutesAgo: 45, attributes: [
            'deferrals' => StrandedDeliveries::MAX_SWEEPS,
        ]);

        $this->sweep();

        $delivery->refresh();

        // Not swept forever. A worker being killed every half hour is a
        // problem for a person, and `dead_letter` is the status §7's log sorts
        // to the top and puts a button beside.
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        $this->assertStringContainsString('never attempted', (string) $delivery->error);
        Queue::assertNothingPushed();
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

        $mine = $this->pending(minutesAgo: 45);

        $other = Project::factory()->create();
        $theirs = app(CurrentProject::class)->run($other, function (): WebhookDelivery {
            Channel::factory()->create([
                'type' => ChannelType::Webhook,
                'config' => ['endpoint' => 'https://other.test/engine/webhook'],
                'secret' => 'other-secret',
            ]);

            return $this->pending(minutesAgo: 45);
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
        $this->pending(minutesAgo: 45);

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
    public function a_stranded_row_sorts_with_the_dead_letters(): void
    {
        $stranded = $this->pending(minutesAgo: 45);
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
        $delivery = $this->pending(minutesAgo: 45);

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
        $delivery = $this->pending(minutesAgo: 45, attributes: [
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
        $delivery = $this->pending(minutesAgo: 45, attributes: [
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
     * records the expectation — the command runs in `__destruct()`. Same helper
     * as {@see SocialListenTest}.
     */
    private function sweep(): void
    {
        /** @var PendingCommand $pending */
        $pending = $this->artisan('publish:sweep-stranded');

        $pending->assertSuccessful()->run();
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
            'next_attempt_at' => null,
            ...$attributes,
        ]);

        // `created_at` is what ages a pending row — see StrandedDeliveries — and
        // the factory stamps it with now().
        $delivery->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $delivery->refresh();
    }
}
