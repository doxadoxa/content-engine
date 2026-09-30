<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\ConnectionHealth;
use App\Publishing\ConnectionSecret;
use App\Publishing\DeliveryExplanation;
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

    #[Test]
    public function a_website_with_an_article_on_its_way_is_not_removed(): void
    {
        $channel = $this->webhook();
        $this->articleDelivery($channel, ['status' => DeliveryStatus::Retrying->value]);

        $this->as($this->owner)->delete(route('channels.destroy', $channel))
            ->assertSessionHasErrors(['channel' => 'An article is on its way to this website. Try again once it has been sent, or pause the website instead.']);

        $this->assertTrue(Channel::query()->whereKey($channel->id)->exists());
    }

    #[Test]
    public function a_website_that_may_have_received_an_article_unheard_is_not_removed(): void
    {
        // Sent, and no answer came back: the row is the receipt identity a
        // retry reconciles against, and deleting it risks a second copy.
        $channel = $this->webhook();
        $delivery = $this->articleDelivery($channel, [
            'status' => DeliveryStatus::DeadLetter->value,
            'article_attempt_started_at' => now(),
            'response_code' => null,
            'error' => 'cURL error 28: Operation timed out',
        ]);

        $this->as($this->owner)->delete(route('channels.destroy', $channel))
            ->assertSessionHasErrors('channel');

        $this->assertTrue(WebhookDelivery::query()->whereKey($delivery->id)->exists());

        // A clear refusal is not uncertain: nothing was published.
        $delivery->forceFill(['response_code' => 401])->save();

        $this->as($this->owner)->delete(route('channels.destroy', $channel))
            ->assertSessionHasNoErrors();
        $this->assertFalse(Channel::query()->whereKey($channel->id)->exists());
    }

    #[Test]
    public function an_article_published_by_hand_that_went_unanswered_also_keeps_its_website(): void
    {
        // Published by hand, so never stamped as a scheduled attempt — but a
        // request went out and timed out, which is just as uncertain.
        $channel = $this->webhook();
        $delivery = $this->articleDelivery($channel, [
            'status' => DeliveryStatus::DeadLetter->value,
            'article_schedule_id' => null,
            'article_attempt_started_at' => null,
            'attempts' => 1,
            'response_code' => null,
            'error' => 'cURL error 28: Operation timed out',
        ]);

        $this->as($this->owner)->delete(route('channels.destroy', $channel))
            ->assertSessionHasErrors('channel');

        $this->assertTrue(WebhookDelivery::query()->whereKey($delivery->id)->exists());
    }

    #[Test]
    public function removing_a_website_sends_its_scheduled_articles_back_to_waiting_for_one(): void
    {
        $channel = $this->webhook(['verified_at' => now()]);
        $delivery = $this->articleDelivery($channel, ['status' => DeliveryStatus::Delivered->value]);
        $sending = $this->schedule($channel, ['status' => 'dispatching', 'delivery_id' => $delivery->id, 'version' => 3]);
        $waiting = $this->schedule($channel, ['status' => 'active']);
        $done = $this->schedule($channel, ['status' => 'completed']);

        $this->as($this->owner)->delete(route('channels.destroy', $channel))
            ->assertSessionHasNoErrors();

        foreach ([$sending, $waiting] as $schedule) {
            $schedule->refresh();
            $this->assertSame('blocked', $schedule->status);
            $this->assertSame(ArticleSchedules::NO_WEBSITE, $schedule->blocked_reason);
            $this->assertNull($schedule->delivery_id);
            $this->assertNull($schedule->channel_id);
        }

        $this->assertSame(4, $sending->version);
        $this->assertSame('completed', $done->refresh()->status);
    }

    #[Test]
    public function a_failed_test_stops_the_website_being_used_until_one_passes(): void
    {
        Http::fake(['receiver.test/*' => Http::sequence()
            ->push('down', 503)
            ->push(['ok' => true])]);
        $channel = $this->webhook(['verified_at' => now()->subDay()]);
        $this->assertTrue(app(ArticleSchedules::class)->compatible($channel));

        $this->as($this->owner)->post(route('channels.ping', $channel))->assertRedirect();

        // The page says "Couldn't connect", and articles agree: none go.
        $channel->refresh();
        $this->assertNull($channel->verified_at);
        $this->assertFalse(app(ArticleSchedules::class)->compatible($channel));

        $this->as($this->owner)->post(route('channels.ping', $channel))->assertRedirect();

        $this->assertTrue(app(ArticleSchedules::class)->compatible($channel->refresh()));
    }

    #[Test]
    public function a_late_test_of_the_old_secret_does_not_verify_the_new_one(): void
    {
        Queue::fake();
        Http::fake(['receiver.test/*' => Http::response(['ok' => true])]);
        $channel = $this->webhook();

        $old = app(WebhookPublisher::class)->ping($channel, $this->project);

        // The owner replaces the secret before the old test has run.
        $this->as($this->owner)->post(route('channels.secret.regenerate', $channel));
        $new = WebhookDelivery::query()->whereKeyNot($old->id)->sole();

        app(WebhookPublisher::class)->attempt($old);

        $this->assertSame(DeliveryStatus::Delivered, $old->refresh()->status);
        $this->assertNull($channel->refresh()->verified_at);

        app(WebhookPublisher::class)->attempt($new);

        $this->assertNotNull($channel->refresh()->verified_at);
    }

    #[Test]
    public function a_late_failure_of_the_old_address_does_not_fail_the_new_one(): void
    {
        Queue::fake();
        // The new test passes; the old one, arriving later, is refused.
        Http::fake(['moved.test/*' => Http::sequence()->push(['ok' => true])->push('gone', 404)]);
        $channel = $this->webhook();
        $old = app(WebhookPublisher::class)->ping($channel, $this->project);

        $this->as($this->owner)->patch(route('channels.update', $channel), ['config' => ['endpoint' => 'https://moved.test/hook']]);
        $new = WebhookDelivery::query()->whereKeyNot($old->id)->sole();
        app(WebhookPublisher::class)->attempt($new);
        $this->assertNotNull($channel->refresh()->verified_at);

        // The old test finishes late and fails. It was a test of the old
        // address, so its failure says nothing about the new one.
        app(WebhookPublisher::class)->attempt($old);

        $this->assertSame(DeliveryStatus::DeadLetter, $old->refresh()->status);
        $this->assertNotNull($channel->refresh()->verified_at);
        $this->as($this->owner)->get(route('channels.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('channels.0.health.state', 'connected'));
    }

    #[Test]
    public function an_older_test_finishing_last_does_not_undo_the_newest_one(): void
    {
        Queue::fake();
        $channel = $this->webhook();
        $older = app(WebhookPublisher::class)->ping($channel, $this->project);
        $this->travel(1)->seconds();
        $newer = app(WebhookPublisher::class)->ping($channel, $this->project);

        // The newest passes first; the older one fails afterwards.
        Http::fake(['receiver.test/*' => Http::sequence()->push(['ok' => true])->push('down', 503)]);
        app(WebhookPublisher::class)->attempt($newer);
        app(WebhookPublisher::class)->attempt($older);

        $this->assertSame(DeliveryStatus::DeadLetter, $older->refresh()->status);
        $this->assertNotNull($channel->refresh()->verified_at);
        $this->assertSame('connected', ConnectionHealth::for($channel)['state']);
    }

    #[Test]
    public function an_older_pass_finishing_last_does_not_verify_over_the_newest_failure(): void
    {
        Queue::fake();
        $channel = $this->webhook();
        $older = app(WebhookPublisher::class)->ping($channel, $this->project);
        $this->travel(1)->seconds();
        $newer = app(WebhookPublisher::class)->ping($channel, $this->project);

        Http::fake(['receiver.test/*' => Http::sequence()->push('down', 503)->push(['ok' => true])]);
        app(WebhookPublisher::class)->attempt($newer);
        app(WebhookPublisher::class)->attempt($older);

        $this->assertSame(DeliveryStatus::Delivered, $older->refresh()->status);
        $this->assertNull($channel->refresh()->verified_at);
        $this->assertSame('failed', ConnectionHealth::for($channel)['state']);
    }

    #[Test]
    public function wordpress_refusing_the_login_is_explained_as_a_login(): void
    {
        Http::fake(['example.test/*' => Http::response(['code' => 'rest_forbidden'], 401)]);
        $wordpress = Channel::factory()->create([
            'name' => 'WordPress',
            'type' => ChannelType::WordPress,
            'config' => ['page_receiver_base' => 'https://example.test/wp-json/avyo/v1', 'username' => 'editor'],
            'secret' => 'app password',
        ]);

        $this->as($this->owner)->post(route('channels.ping', $wordpress))->assertRedirect();

        $ping = WebhookDelivery::query()->sole();
        $expected = "WordPress refused Avyo's login (401). Check the username and application password.";
        $this->assertSame($expected, DeliveryExplanation::for($ping));
        $this->assertSame($expected, ConnectionHealth::for($wordpress->refresh())['detail']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function articleDelivery(Channel $channel, array $attributes): WebhookDelivery
    {
        return WebhookDelivery::query()->create([
            'channel_id' => $channel->id,
            'content_item_id' => ContentItem::factory()->create()->id,
            'delivery_id' => WebhookPayload::newDeliveryId(),
            'payload_snapshot' => ['event' => 'content.published', 'content' => []],
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function schedule(Channel $channel, array $attributes): ArticleSchedule
    {
        return ArticleSchedule::query()->create([
            'content_item_id' => ContentItem::factory()->create()->id, 'channel_id' => $channel->id,
            'publish_at' => now(), 'local_date' => now()->toDateString(), 'local_time' => '09:00',
            'timezone' => 'UTC', 'mode' => 'automatic', 'origin' => 'manager', 'status' => 'active', 'version' => 1,
            ...$attributes,
        ]);
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
