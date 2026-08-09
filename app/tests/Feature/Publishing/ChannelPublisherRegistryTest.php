<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\SocialBand;
use App\Enums\WebhookEvent;
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
use Illuminate\Support\Facades\Queue;
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
        // WordPress is a valid value of the `type` column with no adapter
        // behind it. Answering null would read as "delivered nothing,
        // successfully" — the exact lie phase 6 spent a release removing.
        $this->expectException(UnknownChannelPublisher::class);
        $this->expectExceptionMessage('wordpress');

        $this->registry()->for(ChannelType::WordPress);
    }

    #[Test]
    public function a_transport_registered_against_a_type_it_does_not_claim_is_refused(): void
    {
        // The registry indexes on the type it is handed and used never to
        // consult `supports()`, so a line of wiring with the wrong constant
        // would route every delivery of that type through the wrong transport
        // — here, a Threads post signed with a shared secret and POSTed at an
        // endpoint the channel does not have. The failure surfaced as a
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

        // Two transports since 12.2b registered Threads (§9). The claim is not
        // "webhook is the only one" — that was a count of the transports that
        // happened to exist — it is that a type nobody claims is not
        // publishable, which is what the three selection rules below stand on.
        $this->assertSame([ChannelType::Webhook, ChannelType::Threads], $registry->publishableTypes());
        $this->assertTrue($registry->publishes(ChannelType::Webhook));
        $this->assertTrue($registry->publishes(ChannelType::Threads));
        $this->assertFalse($registry->publishes(ChannelType::WordPress));
        $this->assertFalse($registry->publishes(ChannelType::LinkedIn));
    }

    #[Test]
    public function a_channel_no_transport_claims_can_neither_be_tested_nor_left_unattended(): void
    {
        $registry = $this->registry();

        // What `ChannelController` asks in place of `type === Webhook`.
        $this->assertTrue($registry->canPing(ChannelType::Webhook));
        $this->assertTrue($registry->canAutopublish(ChannelType::Webhook));

        $this->assertFalse($registry->canPing(ChannelType::LinkedIn));
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

        // Threads and the pull API are enabled, verified and opted in, and an
        // article reaches neither: the pull API because no transport claims the
        // type, Threads because a transport claims it and the post guard says
        // an article is not a post. Neither is a delivery that failed — nothing
        // was addressed to them and nothing threw.
        $this->assertSame(
            0,
            WebhookDelivery::query()
                ->whereIn('channel_id', Channel::query()
                    ->whereIn('type', [ChannelType::Threads->value, ChannelType::PullApi->value])
                    ->pluck('id'))
                ->count(),
        );

        $this->publishing()->publish($this->unit);

        $this->assertSame(
            0,
            WebhookDelivery::query()
                ->whereIn('channel_id', Channel::query()
                    ->whereIn('type', [ChannelType::Threads->value, ChannelType::PullApi->value])
                    ->pluck('id'))
                ->count(),
        );
    }

    // ------------------------------------------------------- the band that never

    #[Test]
    public function a_band_that_may_never_autopublish_is_refused_the_unattended_path(): void
    {
        Queue::fake();

        $channel = $this->threadsChannel();
        $conversation = $this->socialPost(SocialBand::Conversation);

        // §5 marks Conversation and Reaction «автопаблиш: никогда» — a reply
        // to a living person sent as the brand with nobody watching, and a
        // comment on the news, which is the one place where being wrong is
        // both fast and public. Never means never, so the refusal lives on the
        // path rather than in whichever caller happens to reach it.
        $this->assertSame([], $this->publishing()->publishAutomatically($conversation));
        $this->assertSame([], $this->publishing()->publishAutomatically($this->socialPost(SocialBand::Reaction)));
        $this->assertSame(0, WebhookDelivery::query()->count());

        // The other half, so the guard cannot be satisfied by refusing
        // everything: the four bands that may earn autopublish still do.
        $question = $this->socialPost(SocialBand::Question);

        $this->assertSame(
            $this->idsOf($channel),
            $this->channelsReached($this->publishing()->publishAutomatically($question)),
        );
    }

    #[Test]
    public function a_band_that_may_never_autopublish_can_still_be_published_by_a_person(): void
    {
        Queue::fake();

        $channel = $this->threadsChannel();
        $conversation = $this->socialPost(SocialBand::Conversation);

        // §4.2 refuses unattended sending, not sending. A human pressing
        // publish is exactly the loop the band requires, and reading the
        // refusal as "this can never go out" would leave the approved reply
        // with nowhere to go.
        $this->assertSame(
            $this->idsOf($channel),
            $this->channelsReached($this->publishing()->publishManually($conversation)),
        );
    }

    // ---------------------------------------------------------- the post guard

    #[Test]
    public function a_social_post_is_never_queued_to_a_webhook_receiver(): void
    {
        $this->webhook('Website');

        $post = ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'state' => ContentItemState::Approved,
            'slug' => 'a-question-nobody-wrote-an-article-about',
            'title' => 'Why does salt eat glass?',
        ]);

        // A 300-character post handed to a static site generator becomes a page
        // with a slug and an article's schema.org type. §9 gives posts their own
        // transport; until one is registered they go nowhere, loudly enough that
        // the caller sees an empty list.
        $this->assertSame([], $this->publishing()->publish($post));
        $this->assertSame([], $this->publishing()->publishManually($post));
        $this->assertSame(0, WebhookDelivery::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function an_article_still_reaches_the_receiver_it_always_did(): void
    {
        $channel = $this->webhook('Website');

        // The other half of the guard, so it cannot be satisfied by refusing
        // everything.
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
            'threads' => Channel::factory()->social(ChannelType::Threads)->create([
                'name' => 'Threads',
                'verified_at' => now(),
                'autopublish' => true,
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

    /** One verified, opted-in Threads channel — the unattended path's target. */
    private function threadsChannel(): Channel
    {
        return Channel::factory()->social(ChannelType::Threads)->create([
            'name' => 'Threads',
            'verified_at' => now(),
            'autopublish' => true,
        ]);
    }

    private function socialPost(SocialBand $band): ContentItem
    {
        return ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'state' => ContentItemState::Approved,
            'social_band' => $band,
            'slug' => 'post-'.$band->value,
            'title' => 'A post of the '.$band->value.' band',
        ]);
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
