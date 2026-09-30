<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Models\Channel;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\ConnectionSecret;
use App\Publishing\WebhookPayload;
use App\Publishing\WebhookPublisher;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The website page: connect with one address, test on save, read the secret
 * back for the developer, and be told plainly when it does not work.
 */
final class WebsiteConnectionTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['website_url' => 'https://example.test']);
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $this->member = User::factory()->create();
        $this->member->projects()->attach($this->project, ['role' => 'operator']);
    }

    #[Test]
    public function one_address_connects_a_website_with_a_generated_secret_and_tests_it(): void
    {
        Http::fake(['blog.example.test/*' => Http::response(['ok' => true])]);

        $this->as($this->owner)->post(route('channels.store'), [
            'type' => 'webhook',
            'config' => ['endpoint' => 'https://www.blog.example.test/avyo/webhook'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('channels.index'));

        $channel = Channel::query()->sole();

        // Named after its host; nobody was asked for a name or a secret.
        $this->assertSame('blog.example.test', $channel->name);
        $this->assertSame(ConnectionSecret::LENGTH, strlen((string) $channel->secret));

        // Saved and tested in one press.
        Http::assertSent(fn (Request $request): bool => $request->header('X-Engine-Event') === ['ping']
            && $request->header('Authorization') === ['Bearer '.$channel->secret]);
        $this->assertNotNull($channel->verified_at);
        $this->as($this->owner)->get(route('channels.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('channels/index')
                ->where('channels.0.health.state', 'connected')
                ->where('channels.0.can_test', true)
                ->missing('channels.0.secret'));
    }

    #[Test]
    public function a_developer_can_still_paste_their_own_secret(): void
    {
        Http::fake(['receiver.test/*' => Http::response([])]);

        $this->as($this->owner)->post(route('channels.store'), [
            'type' => 'webhook',
            'name' => 'Blog',
            'config' => ['endpoint' => 'https://receiver.test/hook'],
            'secret' => 'their-own-secret',
        ])->assertSessionHasNoErrors();

        $this->assertSame('their-own-secret', Channel::query()->sole()->secret);
    }

    #[Test]
    public function a_second_connection_to_the_same_host_gets_its_own_name(): void
    {
        Http::fake(['receiver.test/*' => Http::response([])]);
        ProjectSubscription::query()->where('project_id', $this->project->id)->firstOrFail()
            ->forceFill(['plan' => 'growth', 'limit_overrides' => ['channels' => null]])->save();
        Channel::factory()->create(['name' => 'receiver.test']);

        $this->as($this->owner)->post(route('channels.store'), [
            'type' => 'webhook',
            'config' => ['endpoint' => 'https://receiver.test/second'],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Channel::query()->where('name', 'receiver.test (2)')->exists());
    }

    #[Test]
    public function a_failed_test_is_answered_at_once_with_the_reason(): void
    {
        Http::fake(['receiver.test/*' => Http::response('Service unavailable', 503)]);

        $this->as($this->owner)->post(route('channels.store'), [
            'type' => 'webhook',
            'config' => ['endpoint' => 'https://receiver.test/hook'],
        ])->assertSessionHasNoErrors();

        $ping = WebhookDelivery::query()->sole();

        // Not on the retry ladder for twelve hours: one attempt, then done.
        $this->assertSame(DeliveryStatus::DeadLetter, $ping->status);
        $this->assertSame(1, $ping->attempts);
        $this->assertNull($ping->next_attempt_at);
        Http::assertSentCount(1);

        $this->as($this->owner)->get(route('channels.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('channels.0.health.state', 'failed')
                ->where('channels.0.health.headline', "Couldn't connect")
                ->where('channels.0.health.detail', 'Your website had an error (503).')
                ->where('channels.0.test_pending', false));
    }

    #[Test]
    public function a_test_that_cannot_reach_the_website_is_not_retried_either(): void
    {
        Http::fake(['receiver.test/*' => Http::failedConnection()]);
        $channel = $this->webhook();

        app(ChannelPublisherRegistry::class)->for(ChannelType::Webhook)->ping($channel, $this->project);

        $ping = WebhookDelivery::query()->sole();
        $this->assertSame(DeliveryStatus::DeadLetter, $ping->status);
        $this->assertNull($ping->next_attempt_at);
    }

    #[Test]
    public function article_deliveries_keep_their_retry_ladder(): void
    {
        Queue::fake();
        Http::fake(['receiver.test/*' => Http::response('down', 503)]);
        $channel = $this->webhook();
        $delivery = WebhookDelivery::query()->create([
            'channel_id' => $channel->id,
            'content_item_id' => null,
            'delivery_id' => WebhookPayload::newDeliveryId(),
            'status' => DeliveryStatus::Pending->value,
            'payload_snapshot' => ['event' => 'content.published', 'content' => []],
        ]);

        app(WebhookPublisher::class)->attempt($delivery);

        $this->assertSame(DeliveryStatus::Retrying, $delivery->refresh()->status);
        $this->assertNotNull($delivery->next_attempt_at);
    }

    #[Test]
    public function saving_an_unchanged_connection_keeps_its_verification(): void
    {
        // Made by onboarding: no `page_receiver_base` key at all. The form
        // posts it empty. Missing and empty are the same "none".
        $channel = $this->webhook(['verified_at' => now()]);

        $this->as($this->owner)->patch(route('channels.update', $channel), [
            'name' => 'Renamed',
            'type' => 'webhook',
            'config' => ['endpoint' => 'https://receiver.test/hook', 'page_receiver_base' => '', 'username' => null],
            'is_enabled' => true,
        ])->assertSessionHasNoErrors();

        $channel->refresh();
        $this->assertSame('Renamed', $channel->name);
        $this->assertNotNull($channel->verified_at);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function a_new_address_is_tested_as_soon_as_it_is_saved(): void
    {
        Http::fake(['moved.test/*' => Http::response([])]);
        $channel = $this->webhook(['verified_at' => now()->subDay(), 'config' => ['endpoint' => 'https://receiver.test/hook', 'page_receiver_base' => 'https://receiver.test/pages']]);

        $this->as($this->owner)->patch(route('channels.update', $channel), [
            'config' => ['endpoint' => 'https://moved.test/hook'],
        ])->assertSessionHasNoErrors();

        $channel->refresh();
        $this->assertSame('https://moved.test/hook', $channel->config['endpoint']);
        // The existing-page address the edit form did not show is kept.
        $this->assertSame('https://receiver.test/pages', $channel->config['page_receiver_base']);
        $this->assertTrue($channel->verified_at?->isToday());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://moved.test/hook');
    }

    #[Test]
    public function pausing_and_resuming_sends_only_the_switch(): void
    {
        $channel = $this->webhook(['verified_at' => now()]);

        $this->as($this->owner)->patch(route('channels.update', $channel), ['is_enabled' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($channel->refresh()->is_enabled);
        $this->assertNotNull($channel->verified_at);

        $this->as($this->owner)->patch(route('channels.update', $channel), ['is_enabled' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($channel->refresh()->is_enabled);
    }

    #[Test]
    public function the_owner_reads_the_secret_back_on_request(): void
    {
        $channel = $this->webhook();

        $this->as($this->owner)->postJson(route('channels.secret.reveal', $channel))
            ->assertOk()
            ->assertExactJson(['secret' => 'shared-secret'])
            ->assertHeader('Cache-Control', 'no-store, private');

        // Never in the page itself.
        $this->as($this->owner)->get(route('channels.index'))->assertDontSee('shared-secret', false);
    }

    #[Test]
    public function a_member_who_is_not_the_owner_cannot_read_or_replace_the_secret(): void
    {
        $channel = $this->webhook();

        $this->as($this->member)->postJson(route('channels.secret.reveal', $channel))->assertForbidden();
        $this->as($this->member)->post(route('channels.secret.regenerate', $channel))->assertForbidden();
        $this->as($this->member)->delete(route('channels.destroy', $channel))->assertForbidden();

        $this->assertSame('shared-secret', $channel->refresh()->secret);
    }

    #[Test]
    public function a_new_secret_unverifies_the_website_and_tests_it_again(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['error' => 'Bad signature'], 401)]);
        $channel = $this->webhook(['verified_at' => now()]);

        $this->as($this->owner)->post(route('channels.secret.regenerate', $channel))
            ->assertSessionHasNoErrors()->assertRedirect(route('channels.index'));

        $channel->refresh();
        $this->assertNotSame('shared-secret', $channel->secret);
        $this->assertSame(ConnectionSecret::LENGTH, strlen((string) $channel->secret));
        $this->assertNull($channel->verified_at);
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer '.$channel->secret]);

        $this->as($this->owner)->get(route('channels.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('channels.0.health.state', 'failed'));
    }

    #[Test]
    public function only_a_webhook_has_a_secret_to_read_or_replace(): void
    {
        $wordpress = Channel::factory()->create([
            'type' => ChannelType::WordPress,
            'config' => ['page_receiver_base' => 'https://example.test/wp-json/avyo/v1', 'username' => 'editor'],
            'secret' => 'app password',
        ]);

        $this->as($this->owner)->postJson(route('channels.secret.reveal', $wordpress))->assertNotFound();
        $this->as($this->owner)->post(route('channels.secret.regenerate', $wordpress))->assertNotFound();
    }

    #[Test]
    public function the_owner_removes_a_website(): void
    {
        $channel = $this->webhook();
        WebhookDelivery::query()->create([
            'channel_id' => $channel->id,
            'content_item_id' => null,
            'delivery_id' => WebhookPayload::newDeliveryId(),
            'status' => DeliveryStatus::Delivered->value,
            'payload_snapshot' => ['event' => 'ping'],
        ]);

        $this->as($this->owner)->delete(route('channels.destroy', $channel))
            ->assertSessionHasNoErrors()->assertRedirect(route('channels.index'));

        $this->assertSame(0, Channel::query()->count());
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    /** @param  array<string, mixed>  $attributes */
    private function webhook(array $attributes = []): Channel
    {
        return Channel::factory()->create([
            'name' => 'Blog',
            'type' => ChannelType::Webhook,
            'is_enabled' => true,
            'config' => ['endpoint' => 'https://receiver.test/hook'],
            'secret' => 'shared-secret',
            'verified_at' => null,
            ...$attributes,
        ]);
    }

    private function as(User $user): self
    {
        return $this->actingAs($user)->withSession(['current_project_id' => $this->project->id]);
    }
}
