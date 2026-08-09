<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\Asset;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Publishing\Jobs\DeliverWebhookJob;
use App\Publishing\WebhookPublisher;
use App\Publishing\WebhookSignature;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 6's delivery half: the contract's headers, the retry ladder, the dead
 * letter, replay, and `public_url` coming back.
 */
final class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Channel $channel;

    private ContentItem $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        $this->channel = Channel::factory()->create([
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://receiver.test/engine/webhook'],
            'secret' => 'shared-secret',
        ]);

        $this->unit = ContentItem::factory()->published()->create([
            'title' => 'How to clean windows',
            'slug' => 'how-to-clean-windows',
            'body_markdown' => '## Why',
            'body_html' => '<h2>Why</h2>',
            'summary' => 'Windows get dirty because of salt.',
            'json_ld' => ['@type' => 'HowTo'],
            'faq_json_ld' => ['@type' => 'FAQPage'],
            'author' => ['name' => 'Operator'],
        ]);

        config()->set('queue.default', 'sync');
    }

    // ------------------------------------------------------------- contract

    #[Test]
    public function a_delivery_carries_the_contract_headers_and_a_valid_signature(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['public_url' => 'https://example.test/en/x'])]);

        $delivery = $this->publish();

        Http::assertSent(function (Request $request) use ($delivery): bool {
            $body = $request->body();

            $this->assertSame('Bearer shared-secret', $request->header('Authorization')[0]);
            $this->assertSame($delivery->delivery_id, $request->header('X-Engine-Delivery')[0]);
            $this->assertSame('content.published', $request->header('X-Engine-Event')[0]);
            $this->assertSame('1', $request->header('X-Engine-Contract')[0]);

            // The signature is over `{timestamp}.{raw body}` — the receiver
            // recomputes exactly this.
            $this->assertTrue(WebhookSignature::verify(
                secret: 'shared-secret',
                signature: $request->header('X-Engine-Signature')[0],
                timestamp: $request->header('X-Engine-Timestamp')[0],
                body: $body,
            ));

            return true;
        });

        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
    }

    #[Test]
    public function the_payload_is_one_locale_and_names_its_group(): void
    {
        Http::fake(['receiver.test/*' => Http::response([])]);

        $delivery = $this->publish();

        $content = $delivery->payload_snapshot['content'];

        // One locale per delivery; the group is what a receiver builds hreflang
        // from (§3 of the contract).
        $this->assertSame($this->unit->locale, $content['locale']);
        $this->assertSame($this->unit->locale_group_id, $content['locale_group_id']);
        $this->assertSame('<h2>Why</h2>', $content['html']);
        $this->assertSame('HowTo', $content['json_ld']['@type']);
        $this->assertArrayHasKey('internal_links', $content);
    }

    #[Test]
    public function a_delete_carries_identity_only(): void
    {
        Http::fake(['receiver.test/*' => Http::response([])]);

        $delivery = $this->publisher()->queue($this->unit, $this->channel, WebhookEvent::Deleted);

        $content = $delivery->payload_snapshot['content'];

        // Sending the body of the thing we are asking it to remove is at best
        // confusing.
        $this->assertArrayNotHasKey('markdown', $content);
        $this->assertSame($this->unit->slug, $content['slug']);
    }

    #[Test]
    public function a_ping_carries_no_content(): void
    {
        Http::fake(['receiver.test/*' => Http::response([])]);

        $delivery = $this->publisher()->queue($this->unit, $this->channel, WebhookEvent::Ping);

        $this->assertArrayNotHasKey('content', $delivery->payload_snapshot);
    }

    // ------------------------------------------------------- exit criterion 5

    #[Test]
    public function the_public_url_comes_back_and_lands_on_the_unit(): void
    {
        Http::fake([
            'receiver.test/*' => Http::response(['public_url' => 'https://example.test/en/how-to-clean-windows']),
        ]);

        $this->publish();

        // Without this the GSC matching of phase 9 has nothing to match on.
        $this->assertSame('https://example.test/en/how-to-clean-windows', $this->unit->refresh()->public_url);
    }

    #[Test]
    public function a_receiver_that_returns_no_url_still_counts_as_delivered(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['status' => 'stored'])]);

        $delivery = $this->publish();

        // Under-connected rather than broken: the delivery worked.
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
        $this->assertNull($this->unit->refresh()->public_url);
    }

    #[Test]
    public function a_409_is_success_not_failure(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['status' => 'duplicate'], 409)]);

        $delivery = $this->publish();

        // §2: the receiver saying "already have this" is the idempotency
        // contract working.
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
    }

    // ------------------------------------------------------- exit criterion 4

    #[Test]
    public function an_unreachable_receiver_walks_the_whole_ladder_then_dead_letters(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        Http::fake(['receiver.test/*' => Http::response([], 503)]);

        $delivery = $this->publish();

        // The sync driver runs each delayed retry immediately, so `queue()`
        // walks the whole ladder before returning.
        $delivery->refresh();

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        $this->assertSame(count(config('publishing.backoff')), $delivery->attempts);
        Http::assertSentCount(5);

        Carbon::setTestNow();
    }

    #[Test]
    public function the_ladder_is_the_one_the_contract_publishes(): void
    {
        // 1m → 5m → 30m → 2h → 12h. Published to receiver operators, so it is
        // not a number to tune quietly.
        $this->assertSame([60, 300, 1_800, 7_200, 43_200], config('publishing.backoff'));
    }

    #[Test]
    public function a_refused_signature_is_not_retried(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['error' => 'Bad signature.'], 401)]);

        $delivery = $this->publish()->refresh();

        // A 401 will be a 401 in twelve hours too.
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_rejected_payload_is_not_retried(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['error' => 'No content.'], 422)]);

        $delivery = $this->publish()->refresh();

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        Http::assertSentCount(1);
    }

    #[Test]
    public function throttling_is_retried(): void
    {
        Http::fake(['receiver.test/*' => Http::sequence()
            ->push([], 429)
            ->push(['public_url' => 'https://example.test/x'], 200),
        ]);

        $delivery = $this->publish()->refresh();

        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(2, $delivery->attempts);
    }

    #[Test]
    public function a_replay_after_the_receiver_is_fixed_gets_through(): void
    {
        // One sequence rather than two fakes: Http::fake() *merges* stubs, so
        // a second call does not replace the first and the outage would never
        // end. Five refusals — the whole ladder — then the receiver comes back.
        Http::fake(['receiver.test/*' => Http::sequence()
            ->push([], 503)
            ->push([], 503)
            ->push([], 503)
            ->push([], 503)
            ->push([], 503)
            ->push(['public_url' => 'https://example.test/en/x'], 200),
        ]);

        $dead = $this->publish()->refresh();
        $this->assertSame(DeliveryStatus::DeadLetter, $dead->status);

        $replay = $this->publisher()->replay($dead);

        $this->assertSame(DeliveryStatus::Delivered, $replay->refresh()->status);
        $this->assertSame('https://example.test/en/x', $this->unit->refresh()->public_url);
    }

    #[Test]
    public function a_replay_sends_the_old_bytes_under_a_new_delivery_id(): void
    {
        Http::fake(['receiver.test/*' => Http::response([])]);

        $first = $this->publish();

        // The unit changes after the fact.
        $this->unit->forceFill(['title' => 'A completely different title'])->save();

        $replay = $this->publisher()->replay($first->refresh());

        // §6.2: the snapshot is what goes out. Sending today's content under
        // yesterday's id is what stops a receiver telling a repeat from an
        // edit — so the bytes are old and only the envelope is new.
        $this->assertSame(
            'How to clean windows',
            $replay->payload_snapshot['content']['title'],
        );
        $this->assertNotSame($first->delivery_id, $replay->delivery_id);
    }

    #[Test]
    public function a_channel_with_no_endpoint_dead_letters_immediately(): void
    {
        Http::fake();

        $broken = Channel::factory()->create(['config' => [], 'name' => 'Misconfigured']);

        $delivery = $this->publisher()->queue($this->unit, $broken, WebhookEvent::Published);

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        Http::assertNothingSent();
    }

    // --------------------------------------------------------- the trigger

    #[Test]
    public function publishing_an_approved_unit_delivers_it_and_marks_it_live(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['public_url' => 'https://example.test/en/x'])]);

        $unit = ContentItem::factory()->draft()->create(['slug' => 'ready-to-go']);
        $unit->approve();

        $deliveries = $this->publisher()->publish($unit);

        $this->assertCount(1, $deliveries);
        $this->assertSame(ContentItemState::Published, $unit->refresh()->state);
        $this->assertSame('content.published', $deliveries[0]->payload_snapshot['event']);
    }

    #[Test]
    public function a_live_unit_sent_to_a_new_channel_is_still_a_first_publication(): void
    {
        Http::fake(['receiver.test/*' => Http::response([])]);

        // Another channel may have made it globally live; this receiver has
        // never seen it and therefore needs the first-publication event.
        $deliveries = $this->publisher()->publish($this->unit);

        $this->assertSame('content.published', $deliveries[0]->payload_snapshot['event']);
        $this->assertSame(ContentItemState::Published, $this->unit->refresh()->state);
    }

    #[Test]
    public function changed_content_is_an_update_for_a_channel_that_received_it_before(): void
    {
        Http::fake(['receiver.test/*' => Http::response([])]);

        $this->publisher()->publish($this->unit);

        $this->unit->forceFill([
            'body_markdown' => '## Revised body',
            'body_html' => '<h2>Revised body</h2>',
        ])->save();

        $deliveries = $this->publisher()->publish($this->unit);

        $this->assertSame('content.updated', $deliveries[0]->payload_snapshot['event']);
        $this->assertSame(2, WebhookDelivery::query()->count());
    }

    #[Test]
    public function a_unit_with_nowhere_to_go_is_not_marked_published(): void
    {
        Http::fake();

        $this->channel->forceFill(['is_enabled' => false])->save();

        $unit = ContentItem::factory()->draft()->create(['slug' => 'nowhere-to-go']);
        $unit->approve();

        $this->assertSame([], $this->publisher()->publish($unit));

        // Marking a unit live that no reader can reach would be a lie the
        // feedback loop then measures.
        $this->assertSame(ContentItemState::Approved, $unit->refresh()->state);
    }

    #[Test]
    public function the_console_publishes_approved_content_to_automatic_channels(): void
    {
        Http::fake(['receiver.test/*' => Http::response([])]);

        $this->channel->forceFill(['autopublish' => true, 'verified_at' => now()])->save();

        $unit = ContentItem::factory()->draft()->create(['slug' => 'via-console']);
        $unit->approve();

        /** @var PendingCommand $command */
        $command = $this->artisan('publish:approved', ['project' => $this->project->slug]);
        $command->assertSuccessful()->run();

        $this->assertSame(ContentItemState::Published, $unit->refresh()->state);
    }

    #[Test]
    public function the_console_can_replay_a_dead_letter(): void
    {
        Http::fake(['receiver.test/*' => Http::sequence()
            ->push([], 503)->push([], 503)->push([], 503)->push([], 503)->push([], 503)
            ->push(['public_url' => 'https://example.test/en/x'], 200),
        ]);

        $dead = $this->publish()->refresh();
        $this->assertSame(DeliveryStatus::DeadLetter, $dead->status);

        /** @var PendingCommand $command */
        $command = $this->artisan('publish:replay', ['delivery' => $dead->delivery_id]);
        $command->assertSuccessful()->run();

        $this->assertSame('https://example.test/en/x', $this->unit->refresh()->public_url);
    }

    #[Test]
    public function each_attempt_reuses_the_same_delivery_id(): void
    {
        Http::fake(['receiver.test/*' => Http::sequence()->push([], 503)->push([], 200)]);

        $delivery = $this->publish();

        $ids = [];

        Http::assertSent(function (Request $request) use (&$ids): bool {
            $ids[] = $request->header('X-Engine-Delivery')[0];

            return true;
        });

        // §4: retries share an id precisely so the receiver can dedupe them.
        $this->assertSame([$delivery->delivery_id, $delivery->delivery_id], $ids);
    }

    #[Test]
    public function a_unit_is_published_only_once_a_receiver_confirms(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['public_url' => 'https://example.test/en/x'])]);

        $this->unit->forceFill(['state' => ContentItemState::Approved, 'published_at' => null])->save();

        $this->publisher()->publish($this->unit);

        $this->assertSame(ContentItemState::Published, $this->unit->refresh()->state);
        $this->assertSame('https://example.test/en/x', $this->unit->public_url);
    }

    #[Test]
    public function a_receiver_that_refuses_leaves_the_unit_unpublished(): void
    {
        // The real case: an operator typed a page on their own site into the
        // endpoint field, and it answered 405 to a POST. The panel read
        // "Published" beside an article no reader could reach and a public_url
        // of null, because queueing a delivery was being treated as proof of
        // one.
        Http::fake(['receiver.test/*' => Http::response('Method Not Allowed', 405)]);

        $this->unit->forceFill(['state' => ContentItemState::Approved, 'published_at' => null])->save();

        $this->publisher()->publish($this->unit);

        $this->assertSame(ContentItemState::Approved, $this->unit->refresh()->state);
        $this->assertNull($this->unit->published_at);
        $this->assertSame(
            DeliveryStatus::DeadLetter,
            WebhookDelivery::query()->latest()->firstOrFail()->status,
        );
    }

    #[Test]
    public function a_unit_stays_unpublished_while_a_delivery_is_still_retrying(): void
    {
        Http::fake(['receiver.test/*' => Http::response('', 503)]);

        $this->unit->forceFill(['state' => ContentItemState::Approved, 'published_at' => null])->save();

        $this->publisher()->publish($this->unit);

        // The receiver may yet come back. Until it does the article is not out,
        // and the panel must not say it is.
        $this->assertSame(ContentItemState::Approved, $this->unit->refresh()->state);
    }

    #[Test]
    public function a_private_webhook_target_is_rejected_before_any_request_is_sent(): void
    {
        Http::fake();

        $this->channel->forceFill([
            'config' => ['endpoint' => 'http://169.254.169.254/latest/meta-data'],
        ])->save();

        $delivery = $this->publish()->refresh();

        Http::assertNothingSent();
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        $this->assertStringContainsString('public internet', (string) $delivery->error);
    }

    #[Test]
    public function an_unchanged_revision_is_dispatched_only_once_per_channel(): void
    {
        Queue::fake();
        $this->channel->forceFill(['verified_at' => now()])->save();

        $this->publisher()->publishManually($this->unit);
        $this->publisher()->publishManually($this->unit);

        $this->assertSame(1, WebhookDelivery::query()->count());
        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    #[Test]
    public function adding_an_image_creates_a_new_content_revision(): void
    {
        Queue::fake();
        $this->channel->forceFill(['verified_at' => now()])->save();

        $this->publisher()->publishManually($this->unit);
        Asset::factory()->for($this->unit, 'contentItem')->create();
        $this->publisher()->publishManually($this->unit);

        $this->assertSame(2, WebhookDelivery::query()->count());
        Queue::assertPushed(DeliverWebhookJob::class, 2);
    }

    #[Test]
    public function a_receiver_public_url_must_match_the_project_site(): void
    {
        Http::fake(['receiver.test/*' => Http::response([
            'public_url' => 'https://attacker.test/phishing',
        ])]);

        $delivery = $this->publish()->refresh();

        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertNull($this->unit->refresh()->public_url);
    }

    private function publisher(): WebhookPublisher
    {
        return app(WebhookPublisher::class);
    }

    private function publish(): WebhookDelivery
    {
        return $this->publisher()->queue($this->unit, $this->channel, WebhookEvent::Published);
    }
}
