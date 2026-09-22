<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\WebhookDelivery;
use App\Pages\RegisterPublishedArticle;
use App\Publishing\WordPressPublisher;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WordPressPublisherTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentProject::class)->set(Project::factory()->create(['website_url' => 'https://website.test']));
        $this->channel = Channel::factory()->create(['type' => ChannelType::WordPress, 'config' => ['page_receiver_base' => 'https://website.test/wp-json/avyo/v1', 'username' => 'publisher'], 'secret' => 'application-password']);
        $this->item = ContentItem::factory()->create(['state' => ContentItemState::Approved, 'public_url' => null, 'published_at' => null, 'body_html' => '<p>A useful article.</p>']);
        Queue::fake();
    }

    #[Test]
    public function a_verified_receipt_publishes_and_enrols_the_article_without_a_fabricated_observation(): void
    {
        $this->respond();
        $publisher = app(WordPressPublisher::class);
        $delivery = $publisher->attempt($publisher->queue($this->item, $this->channel));
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(ContentItemState::Published, $this->item->refresh()->state);
        $page = SitePage::query()->sole();
        $this->assertSame($this->item->id, $page->content_item_id);
        $this->assertSame('https://website.test/useful-article/', $page->canonical_url);
        $this->assertNotNull($page->tracked_at);
        $this->assertNull($page->body);
        $this->assertNull($page->read_at);
        $this->assertSame(0, $page->snapshots()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://website.test/wp-json/avyo/v1/articles' && $request->header('Authorization')[0] === 'Basic '.base64_encode('publisher:application-password'));
    }

    #[Test]
    public function false_receipts_and_conflicts_do_not_mark_content_published(): void
    {
        $publisher = app(WordPressPublisher::class);
        foreach ([['content_hash' => str_repeat('a', 64)], ['public_url' => 'https://other.test/article'], ['content_id' => 'other']] as $change) {
            $this->respond($change);
            $delivery = $publisher->attempt($publisher->queue($this->item, $this->channel));
            $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        }
        Http::fake(['*' => Http::response(['public_url' => 'https://website.test/conflict'], 409)]);
        $this->assertSame(DeliveryStatus::DeadLetter, $publisher->attempt($publisher->queue($this->item, $this->channel))->status);
        $this->assertSame(ContentItemState::Approved, $this->item->refresh()->state);
        $this->assertSame(0, SitePage::query()->count());
    }

    #[Test]
    public function a_ping_requires_the_article_capability_receipt(): void
    {
        Http::fake(fn (Request $request) => Http::response(['contract' => 1, 'delivery_id' => $request['delivery_id'], 'capabilities' => ['article_publish' => true]]));
        $publisher = app(WordPressPublisher::class);
        $this->assertSame(DeliveryStatus::Delivered, $publisher->attempt($publisher->ping($this->channel, app(CurrentProject::class)->get()))->status);
        $this->assertNotNull($this->channel->refresh()->verified_at);
        $this->assertTrue($this->channel->config['article_publishing_verified']);
        $this->assertSame(0, SitePage::query()->count());
    }

    #[Test]
    public function measurement_enrolment_is_idempotent_and_preserves_an_explicit_pause(): void
    {
        $this->item->forceFill(['state' => ContentItemState::Published, 'public_url' => 'https://website.test/useful-article/', 'published_at' => now()])->save();
        $service = app(RegisterPublishedArticle::class);
        $page = $service->register($this->item);
        $this->assertNotNull($page);
        $page->forceFill(['tracked_at' => null])->save();
        $this->assertSame($page->id, $service->register($this->item)?->id);
        $this->assertNull($page->refresh()->tracked_at);
        $this->assertSame(1, SitePage::query()->count());
    }

    #[Test]
    public function measurement_enrolment_never_steals_another_articles_canonical_identity(): void
    {
        $other = ContentItem::factory()->published()->create(['public_url' => 'https://website.test/shared/']);
        $service = app(RegisterPublishedArticle::class);
        $original = $service->register($other);
        $this->item->forceFill(['state' => ContentItemState::Published, 'public_url' => $other->public_url])->save();
        $this->assertNull($service->register($this->item));
        $this->assertSame($other->id, $original?->fresh()->content_item_id);
        $this->assertSame(1, SitePage::query()->count());
    }

    #[Test]
    public function an_uncertain_scheduled_delivery_retries_the_same_receipt_identity_and_payload(): void
    {
        $this->channel->forceFill(['verified_at' => now(), 'config' => [...$this->channel->config, 'article_publishing_verified' => true]])->save();
        $publisher = app(WordPressPublisher::class);
        $delivery = $publisher->queue($this->item, $this->channel);
        $schedule = ArticleSchedule::query()->create(['content_item_id' => $this->item->id, 'channel_id' => $this->channel->id, 'publish_at' => now()->subMinute(), 'local_date' => now()->toDateString(), 'local_time' => '09:00', 'timezone' => 'UTC', 'mode' => 'review_first', 'origin' => 'manager', 'status' => 'dispatching', 'version' => 1, 'delivery_id' => $delivery->id]);
        $delivery->forceFill(['article_schedule_id' => $schedule->id, 'article_schedule_version' => 1, 'status' => DeliveryStatus::DeadLetter, 'attempts' => 5, 'article_attempt_started_at' => now()])->save();
        $snapshot = $delivery->payload_snapshot;
        $retry = $publisher->replay($delivery);
        $this->assertSame($delivery->id, $retry->id);
        $this->assertSame($snapshot, $retry->payload_snapshot);
        $this->respond();
        $this->assertSame(DeliveryStatus::Delivered, $publisher->attempt($retry)->status);
        $this->assertSame('completed', $schedule->fresh()->status);
        $this->assertSame(1, WebhookDelivery::query()->where('content_item_id', $this->item->id)->count());
        $publisher->replay($delivery->fresh());
        Http::assertSentCount(1);
    }

    /** @param array<string, mixed> $change */
    private function respond(array $change = []): void
    {
        Http::fake(function (Request $request) use ($change) {
            $content = $request['content'];
            unset($content['published_at']);

            return Http::response([...['contract' => 1, 'delivery_id' => $request['delivery_id'], 'content_id' => $content['id'], 'object_id' => '123', 'status' => 'published', 'content_hash' => hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'public_url' => 'https://website.test/useful-article/'], ...$change]);
        });
    }
}
