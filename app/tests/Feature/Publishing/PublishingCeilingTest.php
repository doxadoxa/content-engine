<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\SocialBand;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\PublishToChannels;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §4.3's ceiling at the moment of sending, which is the only moment that counts.
 *
 * The governor was asked once a week by the planner and by nobody else. A slot
 * is a promise about Wednesday, and approval is a person on Monday morning
 * clearing the queue: three drafts slotted across the week, approved in one
 * sitting, all went out on Monday. Every number in §4.3 survived — the plan
 * said Monday, Wednesday and Friday, and the plan was still on file — while the
 * account posted three times in a day. §10 is explicit that the mitigation is
 * "архитектура, а не дисциплина оператора", so the check belongs on the path
 * every publication takes rather than in the habits of whoever approves.
 *
 * Time is frozen throughout. Every assertion here is about which local day a
 * post lands on, and a suite that runs at 23:59 would otherwise answer
 * differently from one that runs at noon.
 */
final class PublishingCeilingTest extends TestCase
{
    use RefreshDatabase;

    /** A Monday, in the middle of the working morning, in Lisbon. */
    private const string MONDAY = '2026-08-10 09:00:00';

    private Project $project;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::MONDAY, 'Europe/Lisbon'));

        $this->project = Project::factory()->create(['timezone' => 'Europe/Lisbon']);
        $this->operator = User::factory()->create();
        $this->operator->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);

        Channel::factory()->threads()->create([
            'verified_at' => now(),
            'autopublish' => true,
        ]);

        // The deliveries stay pending, which is the state the bug lived in: a
        // queued delivery is a promise to try and the unit is not marked
        // published until a receiver confirms, so between the two the post is
        // on its way to Threads and invisible to anything counting posts.
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function three_drafts_slotted_across_the_week_do_not_all_leave_on_monday(): void
    {
        $channels = app(PublishToChannels::class);

        $monday = $this->aPost(SocialBand::Question, '2026-08-10 10:00:00');
        $wednesday = $this->aPost(SocialBand::OwnData, '2026-08-12 10:00:00');
        $friday = $this->aPost(SocialBand::Season, '2026-08-14 10:00:00');

        // Three different bands on purpose: each is inside its own weekly
        // budget (§5) and the week is inside its ceiling of five, so the only
        // limit that can refuse anything here is the daily one.
        $this->assertNotSame([], $channels->publishManually($monday));
        $this->assertNotSame([], $channels->publishManually($wednesday));
        $this->assertSame([], $channels->publishManually($friday));

        $this->assertSame(2, WebhookDelivery::query()->count());

        $refusal = $channels->refusal($friday);

        $this->assertNotNull($refusal);
        // Which limit, and when it frees. §7 gives the operator five minutes,
        // and "not today" without "and here is when" spends the rest of them.
        $this->assertStringContainsString('2026-08-10 already has 2', $refusal);
        $this->assertStringContainsString('§1', $refusal);
        $this->assertStringContainsString('Tue, 11 Aug 2026', $refusal);
    }

    #[Test]
    public function the_third_publication_of_the_day_tells_the_operator_which_limit_stopped_it(): void
    {
        $channels = app(PublishToChannels::class);

        $channels->publishManually($this->aPost(SocialBand::Question, '2026-08-10 10:00:00'));
        $channels->publishManually($this->aPost(SocialBand::OwnData, '2026-08-12 10:00:00'));

        $third = $this->aPost(SocialBand::Season, '2026-08-14 10:00:00');

        $this->actingAs($this->operator)
            ->from(route('approvals.index'))
            ->post(route('content.publish', $third))
            ->assertSessionHasErrors('publishing');

        $errors = session('errors');

        $this->assertNotNull($errors);
        $this->assertStringContainsString(
            'more than two a day cannibalises our own distribution',
            (string) $errors->first('publishing'),
        );

        $this->assertSame(2, WebhookDelivery::query()->count());
    }

    #[Test]
    public function a_week_at_its_ceiling_refuses_the_sixth_post_whatever_day_it_is_sent_on(): void
    {
        $channels = app(PublishToChannels::class);

        // Five already published across the week — the ordinary ceiling of
        // §4.3, spent. Published rather than queued so the week is full for a
        // reason that has nothing to do with today.
        for ($day = 10; $day <= 14; $day++) {
            $this->aPost(SocialBand::Question, "2026-08-{$day} 10:00:00", [
                'state' => ContentItemState::Published,
                'published_at' => CarbonImmutable::parse("2026-08-{$day} 10:00:00", 'Europe/Lisbon'),
            ]);
        }

        $sixth = $this->aPost(SocialBand::Question, '2026-08-11 10:00:00');

        $this->assertSame([], $channels->publishManually($sixth));

        $refusal = (string) $channels->refusal($sixth);

        $this->assertStringContainsString("the week's ceiling of 5 is spent", $refusal);
        // The week frees on the next Monday, not tomorrow.
        $this->assertStringContainsString('Mon, 17 Aug 2026', $refusal);
    }

    #[Test]
    public function a_reactive_draft_past_its_ttl_is_refused_rather_than_published_late(): void
    {
        $channels = app(PublishToChannels::class);

        // The reaper deletes it, but the reaper runs on a schedule and this is
        // the window in between. §5: "убивается, а не публикуется позже".
        $stale = $this->aPost(SocialBand::Reaction, '2026-08-09 10:00:00', [
            'expires_at' => CarbonImmutable::parse('2026-08-09 18:00:00', 'Europe/Lisbon'),
        ]);

        $this->assertSame([], $channels->publishManually($stale));
        $this->assertStringContainsString('window closed', (string) $channels->refusal($stale));
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function an_article_is_not_governed_by_the_social_ceiling(): void
    {
        $channels = app(PublishToChannels::class);

        Channel::factory()->create([
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://receiver.test/engine/webhook'],
            'secret' => 'shared-secret',
            'verified_at' => now(),
        ]);

        $this->aPost(SocialBand::Question, '2026-08-10 10:00:00');
        $this->aPost(SocialBand::OwnData, '2026-08-10 11:00:00');

        // Two posts already stand against Monday, and the ceiling of §4.3 is
        // about the social account's cadence. An article has no cadence to
        // burn and shares none of that budget.
        $article = ContentItem::factory()->create([
            'state' => ContentItemState::Approved,
            'body_markdown' => "## Why\n\nA paragraph.",
        ]);

        $this->assertNull($channels->refusal($article));
        $this->assertNotSame([], $channels->publishManually($article));
    }

    /**
     * An approved post with a slot, ready to be handed to Threads.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function aPost(SocialBand $band, string $slot, array $attributes = []): ContentItem
    {
        return ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'state' => ContentItemState::Approved,
            'parent_id' => null,
            'social_band' => $band,
            'channel_type' => ChannelType::Threads->value,
            'slot_at' => CarbonImmutable::parse($slot, 'Europe/Lisbon'),
            'published_at' => null,
            'channel_payload' => [
                'segments' => [[
                    'text' => 'Salt eats glass. Anyone else fighting this every winter?',
                    'asset_id' => null,
                    'container_id' => null,
                    'published_id' => null,
                ]],
                'link_attachment' => null,
                'reply_control' => 'everyone',
                'angle' => 'question',
                'permalink' => null,
                'published_root_id' => null,
            ],
            ...$attributes,
        ]);
    }
}
