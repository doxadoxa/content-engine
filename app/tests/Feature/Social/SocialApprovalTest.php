<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Content\PostScore;
use App\Content\UnitScore;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\RejectionReason;
use App\Enums\SocialBand;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §4.3's contour has to end somewhere a person can reach.
 *
 * `social_draft` leaves a unit in `draft`, and §7 gives the operator one screen
 * on which to say yes. Until this suite existed the pipeline tests drove every
 * step directly and all of them passed while the queue that renders their
 * output filtered social posts out — the drafts were real, correct, and
 * unreachable by anything with a button on it.
 *
 * So the sequence tested here is the operator's and not the pipeline's: the
 * post is in the same queue as the articles, a project member approves it, and
 * the state moves. The gate it passes on the way is §2's, not the article
 * checklist's — see {@see PostScore}.
 */
final class SocialApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->member = User::factory()->create();
        // An operator and not the owner: §7 makes approving the daily routine,
        // and `content.approve` carries no `project.owner` middleware for
        // exactly that reason.
        $this->member->projects()->attach($this->project, ['role' => 'operator']);

        app(CurrentProject::class)->set($this->project);

        config()->set('queue.default', 'sync');
    }

    #[Test]
    public function a_social_draft_waits_in_the_same_queue_as_an_article(): void
    {
        $article = ContentItem::factory()->draft()->create(['title' => 'How to clean windows']);
        $post = $this->socialDraft();

        $this->actingAs($this->member)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('approvals/index')
                ->has('drafts.data', 2)
                ->where('drafts.data', fn (mixed $rows): bool => collect(is_array($rows) ? $rows : [])
                    ->pluck('id')
                    ->diff([$article->getKey(), $post->getKey()])
                    ->isEmpty())
            );
    }

    #[Test]
    public function the_queue_says_which_row_is_a_post(): void
    {
        $this->socialDraft();

        $this->actingAs($this->member)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // §7 gives the operator one screen. A second queue would be a
                // second habit, so the post shares this one and says what it is
                // on the row instead.
                ->where('drafts.data.0.is_social', true)
                ->where('drafts.data.0.social_band', 'question')
                ->where('drafts.data.0.segments', 1)
                ->where('drafts.data.0.excerpt', $this->text())
            );
    }

    #[Test]
    public function a_project_member_approves_a_social_draft(): void
    {
        $post = $this->socialDraft();

        $this->actingAs($this->member)
            ->post(route('content.approve', $post))
            ->assertRedirect();

        $this->assertSame(ContentItemState::Approved, $post->refresh()->state);
    }

    #[Test]
    public function approving_a_post_reaches_the_channel_the_same_way_an_article_does(): void
    {
        Http::fake([
            'graph.threads.net/v1.0/*/threads_publishing_limit*' => Http::response([
                'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]],
            ]),
            'graph.threads.net/v1.0/*/threads_publish' => Http::response(['id' => 'post-1']),
            'graph.threads.net/v1.0/*/threads' => Http::response(['id' => 'container-1']),
            'graph.threads.net/v1.0/*' => Http::response(['permalink' => 'https://www.threads.net/@brand/post/1']),
        ]);

        $channel = Channel::factory()->create([
            'type' => ChannelType::Threads,
            'config' => ['user_id' => '1234567890'],
            'autopublish' => true,
            'verified_at' => now(),
        ]);

        $post = $this->socialDraft();

        $this->actingAs($this->member)
            ->post(route('content.approve', $post))
            ->assertRedirect();

        // The point is not that it published — it is that approval funnels into
        // PublishToChannels, which is where §4.3's ceiling is enforced. A path
        // that wrote `approved` and stopped would bypass the governor.
        $this->assertDatabaseHas('webhook_deliveries', [
            'content_item_id' => $post->getKey(),
            'channel_id' => $channel->getKey(),
        ]);
    }

    #[Test]
    public function a_post_the_platform_would_refuse_is_not_approvable(): void
    {
        $post = $this->socialDraft(['channel_payload' => $this->payload(str_repeat('a', 501))]);

        // Named rather than merely counted. Before 12.6 a post was unapprovable
        // for the wrong reason — the article checklist wanted a limitations
        // section — so a test that only asserts "refused" passes against the
        // bug it was written for.
        $this->actingAs($this->member)
            ->post(route('content.approve', $post))
            ->assertSessionHasErrors(['approval' => $this->refusal('Inside the platform\'s limit')]);

        $this->assertSame(ContentItemState::Draft, $post->refresh()->state);
    }

    #[Test]
    public function a_bare_link_is_not_approvable(): void
    {
        $post = $this->socialDraft(['channel_payload' => $this->payload('https://example.test/a-page')]);

        $this->actingAs($this->member)
            ->post(route('content.approve', $post))
            ->assertSessionHasErrors(['approval' => $this->refusal('More than a link')]);

        $this->assertSame(ContentItemState::Draft, $post->refresh()->state);
    }

    #[Test]
    public function a_reaction_whose_window_has_passed_is_not_approvable(): void
    {
        Carbon::setTestNow('2026-03-02 09:00:00');

        $post = $this->socialDraft([
            'social_band' => SocialBand::Reaction,
            'expires_at' => Carbon::parse('2026-03-01 09:00:00'),
        ]);

        $this->actingAs($this->member)
            ->post(route('content.approve', $post))
            ->assertSessionHasErrors(['approval' => $this->refusal('Still inside its window')]);

        $this->assertSame(ContentItemState::Draft, $post->refresh()->state);
    }

    #[Test]
    public function an_ordinary_post_is_not_blocked_by_the_article_checklist(): void
    {
        // The regression itself. Every critical check in ArticleScore is about
        // a page — a limitations section, a house-style reading of two thousand
        // words — and a 300-character post fails all of them, which is what
        // made §4.3 produce drafts nothing could approve.
        $scored = app(UnitScore::class)->for($this->socialDraft());

        $this->assertTrue($scored['publishable'], implode(', ', $scored['blocking']));
        $this->assertSame([], $scored['blocking']);
    }

    #[Test]
    public function a_post_can_be_sent_back_with_a_reason(): void
    {
        $post = $this->socialDraft();

        $this->actingAs($this->member)
            ->post(route('content.reject', $post), [
                'reason' => RejectionReason::OffBrand->value,
                'note' => 'Reads like an advert.',
            ])
            ->assertRedirect();

        $post->refresh();

        $this->assertSame(ContentItemState::Draft, $post->state);
        $this->assertNotNull($post->reviewed_at);
        $this->assertSame(RejectionReason::OffBrand->value, $post->review['reason'] ?? null);
    }

    /** The sentence the controller builds around a blocking check's label. */
    private function refusal(string $label): string
    {
        return "This draft is not publishable: {$label}.";
    }

    /** @param array<string, mixed> $attributes */
    private function socialDraft(array $attributes = []): ContentItem
    {
        return ContentItem::factory()->draft()->create([
            'type' => ContentItemType::SocialPost,
            'title' => 'What breaks a marble worktop',
            'social_band' => SocialBand::Question,
            'channel_type' => ChannelType::Threads->value,
            'channel_payload' => $this->payload($this->text()),
            'body_markdown' => $this->text(),
            'summary' => $this->text(),
            'scheduled_for' => '2026-03-05',
            ...$attributes,
        ]);
    }

    private function text(): string
    {
        return 'Most supermarket sprays etch marble within about 30 seconds. '
            .'What do you actually use on yours?';
    }

    /** @return array<string, mixed> */
    private function payload(string ...$texts): array
    {
        $segments = [];

        foreach ($texts as $position => $text) {
            $segments[] = [
                'position' => $position,
                'text' => $text,
                'asset_id' => null,
                'container_id' => null,
                'published_id' => null,
            ];
        }

        return [
            'segments' => $segments,
            'link_attachment' => null,
            'reply_control' => 'everyone',
            'angle' => 'question',
            'permalink' => null,
            'published_root_id' => null,
        ];
    }
}
