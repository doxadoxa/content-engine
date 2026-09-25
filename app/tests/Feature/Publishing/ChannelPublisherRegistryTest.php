<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\WebhookEvent;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\Exceptions\MismatchedChannelPublisher;
use App\Publishing\Exceptions\UnknownChannelPublisher;
use App\Publishing\PublishToChannels;
use App\Publishing\WebhookPublisher;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §9's seam: which transport reaches which channel, and which channels a
 * publication is addressed to.
 *
 * The three selection rules are the load-bearing part. They were
 * `where('type', ChannelType::Webhook)` on three methods of `WebhookPublisher`,
 * they now read the publishable types off the registry, and they must select
 * exactly what they selected before — so every rule here is asserted against a
 * project holding channels of several types in several states, not against the
 * one channel that would make any of them look right.
 */
final class ChannelPublisherRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private ContentItem $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        $this->unit = ContentItem::factory()->published()->create([
            'title' => 'How to clean windows',
            'slug' => 'how-to-clean-windows',
            'body_html' => '<h2>Why</h2>',
        ]);

        Http::fake(['receiver.test/*' => Http::response([])]);
        config()->set('queue.default', 'sync');
    }

    // ------------------------------------------------------------- resolution

    #[Test]
    public function a_webhook_channel_resolves_to_the_webhook_transport(): void
    {
        $this->assertInstanceOf(
            WebhookPublisher::class,
            $this->registry()->for(ChannelType::Webhook),
        );

        $this->assertTrue($this->registry()->for(ChannelType::Webhook)->supports(ChannelType::Webhook));
    }

    #[Test]
    public function a_type_no_transport_claims_throws_rather_than_quietly_doing_nothing(): void
    {
        // Pull API is a valid value of the `type` column with no adapter
        // behind it. Answering null would read as "delivered nothing,
        // successfully" — the exact lie phase 6 spent a release removing.
        $this->expectException(UnknownChannelPublisher::class);
        $this->expectExceptionMessage('pull_api');

        $this->registry()->for(ChannelType::PullApi);
    }

    #[Test]
    public function a_transport_registered_against_a_type_it_does_not_claim_is_refused(): void
    {
        // The registry indexes on the type it is handed and used never to
        // consult `supports()`, so a line of wiring with the wrong constant
        // would route every delivery of that type through the wrong transport
        // — here, a WordPress article signed with a shared secret and POSTed at
        // an endpoint the channel does not have. The failure surfaced as a
        // delivery error three layers from the wrong word.
        $registry = $this->registry()->register(ChannelType::WordPress, WebhookPublisher::class);

        $this->expectException(MismatchedChannelPublisher::class);
        $this->expectExceptionMessage('wordpress');

        $registry->for(ChannelType::WordPress);
    }

    #[Test]
    public function a_delivery_resolves_to_the_transport_of_its_own_channel(): void
    {
        $channel = $this->webhook('Website');

        $delivery = $this->registry()->for(ChannelType::Webhook)
            ->queue($this->unit, $channel);

        // Nothing in the queued job's payload says what kind of thing it is
        // delivering; the row is addressed to a channel, and the channel knows.
        $this->assertInstanceOf(
            WebhookPublisher::class,
            $this->registry()->forDelivery($delivery->fresh() ?? $delivery),
        );
    }

    #[Test]
    public function only_a_type_with_a_transport_is_publishable(): void
    {
        $registry = $this->registry();

        // The claim is not "webhook is the only one" — that was a count of the
        // transports that happened to exist — it is that a type nobody claims
        // is not publishable, which is what the three selection rules below
        // stand on.
        $this->assertSame([ChannelType::Webhook, ChannelType::WordPress], $registry->publishableTypes());
        $this->assertTrue($registry->publishes(ChannelType::Webhook));
        $this->assertTrue($registry->publishes(ChannelType::WordPress));
        $this->assertFalse($registry->publishes(ChannelType::PullApi));
    }

    #[Test]
    public function a_channel_no_transport_claims_can_neither_be_tested_nor_left_unattended(): void
    {
        $registry = $this->registry();

        // What `ChannelController` asks in place of `type === Webhook`.
        $this->assertTrue($registry->canPing(ChannelType::Webhook));
        $this->assertTrue($registry->canAutopublish(ChannelType::Webhook));

        $this->assertFalse($registry->canPing(ChannelType::PullApi));
        $this->assertFalse($registry->canAutopublish(ChannelType::PullApi));
    }

    // -------------------------------------------------------- the three rules

    #[Test]
    public function publishing_selects_every_enabled_channel_a_transport_can_reach(): void
    {
        $channels = $this->aChannelOfEveryKind();

        $this->assertSame(
            $this->idsOf($channels['auto'], $channels['verified'], $channels['unverified']),
            $this->channelsReached($this->publishing()->publish($this->unit)),
        );
    }

    #[Test]
    public function automatic_publishing_selects_only_verified_channels_that_opted_in(): void
    {
        $channels = $this->aChannelOfEveryKind();
        $this->unit->forceFill(['state' => ContentItemState::Approved, 'published_at' => null, 'factcheck' => ['passed' => true]])->save();
        ArticleSchedule::query()->create([
            'content_item_id' => $this->unit->id, 'channel_id' => $channels['auto']->id,
            'publish_at' => now(), 'local_date' => now($this->project->timezone)->toDateString(),
            'local_time' => now($this->project->timezone)->format('H:i'), 'timezone' => $this->project->timezone,
            'mode' => 'automatic', 'origin' => 'manager', 'status' => 'active', 'version' => 1,
        ]);

        // Unattended, so both halves matter: somebody turned it on, and the
        // connection has answered at least once.
        $this->assertSame(
            $this->idsOf($channels['auto']),
            $this->channelsReached($this->publishing()->publishAutomatically($this->unit)),
        );
    }

    #[Test]
    public function manual_publishing_selects_every_verified_channel(): void
    {
        $channels = $this->aChannelOfEveryKind();

        // A person is watching, so the auto-publish toggle is not their answer
        // to give twice — but an unproven connection is still not a target.
        $this->assertSame(
            $this->idsOf($channels['auto'], $channels['verified']),
            $this->channelsReached($this->publishing()->publishManually($this->unit)),
        );
    }

    #[Test]
    public function a_channel_of_a_type_nothing_can_reach_is_never_selected(): void
    {
        $this->aChannelOfEveryKind();

        // The pull API is enabled, verified and opted in, and an article does
        // not reach it because no transport claims the type. That is not a
        // delivery that failed — nothing was addressed to it and nothing threw.
        $this->assertSame(
            0,
            WebhookDelivery::query()
                ->whereIn('channel_id', Channel::query()
                    ->where('type', ChannelType::PullApi->value)
                    ->pluck('id'))
                ->count(),
        );

        $this->publishing()->publish($this->unit);

        $this->assertSame(
            0,
            WebhookDelivery::query()
                ->whereIn('channel_id', Channel::query()
                    ->where('type', ChannelType::PullApi->value)
                    ->pluck('id'))
                ->count(),
        );
    }

    // ---------------------------------------------------------- the receiver

    #[Test]
    public function an_article_still_reaches_the_receiver_it_always_did(): void
    {
        $channel = $this->webhook('Website');

        $deliveries = $this->publishing()->publish($this->unit);

        $this->assertSame($this->idsOf($channel), $this->channelsReached($deliveries));
        $this->assertSame(WebhookEvent::Published->value, $deliveries[0]->payload_snapshot['event']);
    }

    // ------------------------------------------------------------------ setup

    /**
     * One channel per interesting combination of type and state — the point
     * being that each rule below has to say no to most of them.
     *
     * @return array<string, Channel>
     */
    private function aChannelOfEveryKind(): array
    {
        return [
            'auto' => $this->webhook('Automatic', ['verified_at' => now(), 'autopublish' => true]),
            'verified' => $this->webhook('Verified', ['verified_at' => now()]),
            'unverified' => $this->webhook('Never tested'),
            'disabled' => $this->webhook('Switched off', [
                'verified_at' => now(),
                'autopublish' => true,
                'is_enabled' => false,
            ]),
            'pull' => Channel::factory()->create([
                'name' => 'Pull API',
                'type' => ChannelType::PullApi,
                'config' => [],
                'verified_at' => now(),
                'autopublish' => true,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function webhook(string $name, array $attributes = []): Channel
    {
        return Channel::factory()->create([
            'name' => $name,
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://receiver.test/'.str($name)->slug()],
            'secret' => 'shared-secret',
            ...$attributes,
        ]);
    }

    /**
     * @param  list<WebhookDelivery>  $deliveries
     * @return list<string>
     */
    private function channelsReached(array $deliveries): array
    {
        return array_values((new Collection($deliveries))
            ->pluck('channel_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->sort()
            ->all());
    }

    /**
     * @return list<string>
     */
    private function idsOf(Channel ...$channels): array
    {
        return array_values((new Collection($channels))
            ->map(static fn (Channel $channel): string => (string) $channel->getKey())
            ->sort()
            ->all());
    }

    private function registry(): ChannelPublisherRegistry
    {
        return app(ChannelPublisherRegistry::class);
    }

    private function publishing(): PublishToChannels
    {
        return app(PublishToChannels::class);
    }
}
