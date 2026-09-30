<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\ProjectStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\Articles\PublicationStatus;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One presenter says where an article is, for every screen. These pin each
 * state to the words and the next step an owner sees.
 */
final class PublicationStatusTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T08:00:00Z'));
        Queue::fake();
        $this->project = Project::factory()->create(['autopublish' => true, 'timezone' => 'UTC']);
        app(CurrentProject::class)->set($this->project);
        $this->channel = Channel::factory()->create(['type' => ChannelType::Webhook, 'name' => 'Main site',
            'config' => ['endpoint' => 'https://receiver.test/articles'], 'is_enabled' => true, 'verified_at' => now()]);
    }

    #[Test]
    public function an_automatic_article_on_course_is_scheduled_with_its_day_and_time(): void
    {
        $item = $this->article(ContentItemState::Draft, ['factcheck' => ['passed' => true]]);
        $this->schedule($item, '2026-09-16 09:00');

        $status = $this->present($item);

        $this->assertSame('scheduled', $status['key']);
        $this->assertSame('neutral', $status['tone']);
        $this->assertSame('Scheduled', $status['label']);
        $this->assertSame('Publishes tomorrow at 09:00. Avyo checks it first.', $status['detail']);
        $this->assertNull($status['action']);
    }

    #[Test]
    public function a_review_first_article_is_waiting_for_you_not_an_error(): void
    {
        $item = $this->article(ContentItemState::Draft);
        $this->schedule($item, '2026-09-18 09:00', ['mode' => 'review_first']);

        $status = $this->present($item);

        $this->assertSame('waiting', $status['key']);
        $this->assertSame('attention', $status['tone']);
        $this->assertSame('Waiting for you', $status['label']);
        $this->assertSame('Approve it to publish Fri 18 Sep at 09:00.', $status['detail']);
        $this->assertSame(['label' => 'Approve', 'kind' => 'approve', 'href' => "/content/{$item->id}/approve",
            'method' => 'post', 'owner_only' => false, 'external' => false], $status['action']);
    }

    #[Test]
    public function a_due_article_held_for_approval_says_when_it_was_due(): void
    {
        $item = $this->article(ContentItemState::Draft);
        $this->schedule($item, '2026-09-15 07:00', ['mode' => 'review_first', 'status' => 'blocked', 'blocked_reason' => ArticleSchedules::NEEDS_APPROVAL]);

        $status = $this->present($item);

        $this->assertSame('waiting', $status['key']);
        $this->assertSame('It was due today at 07:00. Approve it to publish now.', $status['detail']);
        $this->assertSame('approve', $status['action']['kind'] ?? null);
    }

    #[Test]
    public function a_young_pending_delivery_is_sending(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::Pending, 'response_code' => null, 'attempts' => 0, 'delivered_at' => null]);
        $this->schedule($item, '2026-09-15 07:55', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame(['sending', 'progress', 'Sending', 'Sending to your website…'],
            [$status['key'], $status['tone'], $status['label'], $status['detail']]);
        $this->assertTrue(app(ArticleSchedules::class)->props($item->fresh())['in_flight']);
    }

    #[Test]
    public function a_stranded_delivery_is_delayed_and_says_avyo_will_try_again(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::Pending, 'response_code' => null, 'attempts' => 0, 'delivered_at' => null]);
        $delivery->forceFill(['created_at' => now()->subMinutes(70)])->save();
        $this->schedule($item, '2026-09-15 06:50', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame('delayed', $status['key']);
        $this->assertSame('attention', $status['tone']);
        $this->assertSame('Taking longer than usual. Avyo will try again automatically.', $status['detail']);
    }

    #[Test]
    public function a_retrying_delivery_explains_the_failure_and_the_next_try(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::Retrying, 'response_code' => 503, 'attempts' => 2,
            'error' => 'receiver answered 503', 'next_attempt_at' => CarbonImmutable::parse('2026-09-15T08:30:00Z'), 'delivered_at' => null]);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame('retrying', $status['key']);
        $this->assertSame('Retrying', $status['label']);
        $this->assertSame('Your website had an error (503). Avyo will try again today at 08:30.', $status['detail']);
        $this->assertSame('2026-09-15T08:30:00+00:00', $status['when']);
        $this->assertNull($status['action']);
    }

    #[Test]
    public function a_refused_signature_points_at_the_website_connection(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::DeadLetter, 'response_code' => 401, 'error' => 'receiver refused with 401', 'delivered_at' => null]);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame(['failed', 'problem', "Couldn't publish"], [$status['key'], $status['tone'], $status['label']]);
        $this->assertStringContainsString("rejected Avyo's signature (401)", (string) $status['detail']);
        $this->assertSame(['connect', '/channels', true], [$status['action']['kind'] ?? null, $status['action']['href'] ?? null, $status['action']['owner_only'] ?? null]);
    }

    #[Test]
    public function a_dead_letter_the_website_may_accept_later_offers_try_again(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::DeadLetter, 'response_code' => 500, 'attempts' => 5, 'error' => 'receiver answered 500', 'delivered_at' => null]);
        $this->schedule($item, '2026-09-14 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame('failed', $status['key']);
        $this->assertSame('Your website had an error (500).', $status['detail']);
        $this->assertSame(['Try again', 'retry', "/deliveries/{$delivery->id}/replay", 'post'],
            [$status['action']['label'] ?? null, $status['action']['kind'] ?? null, $status['action']['href'] ?? null, $status['action']['method'] ?? null]);
    }

    #[Test]
    public function each_blocked_reason_links_to_its_own_fix(): void
    {
        $cases = [
            [ArticleSchedules::NO_WEBSITE, 'failed', 'connect', '/channels'],
            [ArticleSchedules::MISSED_DATE, 'waiting', 'reschedule', '#publication'],
            [ArticleSchedules::PAUSED, 'waiting', 'plan', '/billing'],
            ['The fact check has not passed. Review the article before publishing.', 'waiting', 'review', '/content/'],
            ['An active plan or available publication grace is required.', 'waiting', 'plan', '/billing'],
            [ArticleSchedules::CHOOSE_WEBSITE, 'waiting', 'reschedule', '#publication'],
        ];

        foreach ($cases as [$reason, $key, $kind, $href]) {
            $item = $this->article(ContentItemState::Draft);
            $this->schedule($item, '2026-09-15 07:00', ['status' => 'blocked', 'blocked_reason' => $reason]);

            $status = $this->present($item);

            $this->assertSame($key, $status['key'], $reason);
            $this->assertSame($kind, $status['action']['kind'] ?? null, $reason);
            $this->assertStringContainsString($href, (string) ($status['action']['href'] ?? ''), $reason);
            $this->assertStringNotContainsString('delivery', strtolower((string) $status['detail']), $reason);
        }
    }

    #[Test]
    public function a_paused_business_asks_to_resume_rather_than_to_buy_a_plan(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Paused])->save();
        $item = $this->article(ContentItemState::Approved);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'blocked', 'blocked_reason' => ArticleSchedules::PAUSED]);

        $status = $this->present($item);

        $this->assertSame('paused', $status['key']);
        $this->assertSame('settings', $status['action']['kind'] ?? null);
    }

    #[Test]
    public function a_published_article_links_to_the_live_page(): void
    {
        $item = $this->article(ContentItemState::Published, ['public_url' => 'https://example.test/post', 'published_at' => now()->subDay()]);

        $status = $this->present($item);

        $this->assertSame(['published', 'success', 'Published', 'Published 14 Sep 2026.'],
            [$status['key'], $status['tone'], $status['label'], $status['detail']]);
        $this->assertSame(['View on your site', 'view', 'https://example.test/post', true],
            [$status['action']['label'] ?? null, $status['action']['kind'] ?? null, $status['action']['href'] ?? null, $status['action']['external'] ?? null]);
    }

    #[Test]
    public function the_quiet_states_are_plain(): void
    {
        $this->assertSame('planned', $this->present($this->article(ContentItemState::Idea))['key']);
        $this->assertSame('Writing', $this->present($this->article(ContentItemState::Queued))['label']);
        $this->assertSame('ready', $this->present($this->article(ContentItemState::Approved))['key']);

        $paused = $this->article(ContentItemState::Approved);
        $this->schedule($paused, '2026-09-16 09:00', ['status' => 'paused']);
        $this->assertSame('Paused', $this->present($paused)['label']);

        $canceled = $this->article(ContentItemState::Approved);
        $this->schedule($canceled, '2026-09-16 09:00', ['status' => 'canceled']);
        $this->assertSame('Not scheduled', $this->present($canceled)['label']);
    }

    #[Test]
    public function the_props_carry_the_presentation_and_keep_their_old_keys(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $this->schedule($item, '2026-09-16 09:00');

        $props = app(ArticleSchedules::class)->props($item);

        $this->assertSame('scheduled', $props['status']);
        $this->assertSame('scheduled', $props['presentation']['key']);
        $this->assertFalse($props['in_flight']);
        $this->assertSame(['available' => true, 'reason' => null], $props['publish_now']);
        $this->assertArrayHasKey('can_schedule', $props);
    }

    /** @return array<string, mixed> */
    private function present(ContentItem $item): array
    {
        return PublicationStatus::for($item->fresh() ?? $item);
    }

    /** @param array<string, mixed> $attributes */
    private function article(ContentItemState $state, array $attributes = []): ContentItem
    {
        return ContentItem::factory()->create(['state' => $state, 'body_html' => '<p>Text.</p>', ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(ContentItem $item, string $at, array $attributes = []): ArticleSchedule
    {
        $when = CarbonImmutable::parse($at, 'UTC');

        return ArticleSchedule::query()->create([
            'content_item_id' => $item->id, 'channel_id' => $this->channel->id, 'publish_at' => $when,
            'local_date' => $when->toDateString(), 'local_time' => $when->format('H:i'), 'timezone' => 'UTC',
            'mode' => 'automatic', 'held_for_review' => false, 'origin' => 'manager', 'status' => 'active', 'version' => 1,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function delivery(ContentItem $item, array $attributes): WebhookDelivery
    {
        return WebhookDelivery::factory()->create(['channel_id' => $this->channel->id, 'content_item_id' => $item->id,
            'payload_snapshot' => ['event' => 'content.published'], ...$attributes]);
    }
}
