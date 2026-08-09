<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\DeliveryStatus;
use App\Integrations\Threads\ThreadsConnection;
use App\Models\Asset;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\ThreadsPublisher;
use App\Publishing\ThreadsRateLimiter;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §9's second transport, and exit criterion 4.
 *
 * Threads offers no idempotency key and publishes in two phases, so the only
 * thing standing between an interrupted worker and a second root post is the
 * journal in `channel_payload`. Most of what follows is that one claim asked
 * from several directions: interrupted between the two calls, interrupted
 * mid-chain, replayed after the fact.
 *
 * Nothing reaches the network. `Http::preventStrayRequests()` is on globally,
 * and every test here fakes the four addresses the adapter knows —
 * `POST /{user}/threads`, `POST /{user}/threads_publish`,
 * `GET /{user}/threads_publishing_limit` and a plain `GET` for the permalink and
 * the connection test.
 */
final class ThreadsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** What {@see ProjectIntegrationFactory::threads()} connects. */
    private const string USER_ID = '17841400000000000';

    /** And the token it connects with. */
    private const string TOKEN = 'threads-long-lived-token';

    private Project $project;

    private Channel $channel;

    private ProjectIntegration $integration;

    private ContentItem $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        config()->set('services.threads.client_id', 'threads-app-id');
        config()->set('services.threads.client_secret', 'threads-app-secret');
        config()->set('queue.default', 'sync');

        $this->channel = Channel::factory()->threads()->create(['verified_at' => now()]);
        $this->integration = ProjectIntegration::factory()->threads()->create();
        $this->post = $this->aPost(['Salt eats glass. Anyone else fighting this every winter?']);
    }

    // ------------------------------------------------------------- the seam

    #[Test]
    public function a_threads_channel_resolves_to_the_threads_transport(): void
    {
        $registry = app(ChannelPublisherRegistry::class);

        $this->assertInstanceOf(ThreadsPublisher::class, $registry->for(ChannelType::Threads));
        $this->assertTrue($registry->canPing(ChannelType::Threads));
        $this->assertTrue($registry->canAutopublish(ChannelType::Threads));
    }

    #[Test]
    public function publishing_creates_a_container_then_publishes_it(): void
    {
        $this->fakeApi();

        $delivery = $this->publisher()->queue($this->post, $this->channel);

        $container = $this->calls('/threads');
        $publish = $this->calls('/threads_publish');

        $this->assertCount(1, $container);
        $this->assertSame('TEXT', $container[0]['media_type']);
        $this->assertSame('Salt eats glass. Anyone else fighting this every winter?', $container[0]['text']);
        $this->assertArrayNotHasKey('reply_to_id', $container[0]);

        $this->assertCount(1, $publish);
        $this->assertSame('container-1', $publish[0]['creation_id']);

        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
        $this->assertSame('post-1', $this->journal()['published_root_id']);
        $this->assertSame('https://www.threads.net/@engine/post/AAA', $this->journal()['permalink']);
        $this->assertSame(ContentItemState::Published, $this->post->refresh()->state);
    }

    #[Test]
    public function an_unchanged_post_is_queued_only_once(): void
    {
        Queue::fake();

        $this->publisher()->queue($this->post, $this->channel);
        $this->publisher()->queue($this->post, $this->channel);

        // The dispatch key is over the post's content, not over the journal —
        // otherwise every attempt would look like a new revision and every
        // retry would be a new delivery, which is a second root post arriving
        // through the front door.
        $this->assertSame(1, WebhookDelivery::query()->count());
    }

    // ------------------------------------------------------- exit criterion 4

    #[Test]
    public function a_publish_that_never_answers_stops_rather_than_presenting_the_container_again(): void
    {
        Queue::fake();

        // The one failure the journal structurally cannot see: the request
        // left, the platform never answered, and the post may or may not be
        // live. There is no way to ask — Threads has no client-side
        // idempotency key — so another go is a coin flip on whether the account
        // gets a second root post.
        $this->fakeApi(publish: fn () => throw new ConnectionException('cURL error 28: operation timed out'));

        $delivery = $this->publisher()->queue($this->post, $this->channel);
        $this->publisher()->attempt($delivery);

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        $this->assertStringContainsString('may be live', (string) $delivery->error);

        // The container is on the journal, unpublished, so a person can find
        // the post and finish it by hand. What must not happen is the engine
        // deciding for itself.
        $this->assertSame('container-1', $this->journal()['segments'][0]['container_id']);
        $this->assertNull($this->journal()['segments'][0]['published_id']);

        // And it stays stopped. A settled delivery attempted again makes no
        // further call, so nothing can quietly present the container a second
        // time on the next pass of the queue.
        $this->publisher()->attempt($delivery);

        $this->assertCount(1, $this->calls('/threads'));
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
    }

    #[Test]
    public function a_platform_that_declines_the_publish_is_still_retried(): void
    {
        Queue::fake();

        // The other side of the same coin, and the reason the two are not one
        // branch. A 503 is the platform *answering*: it declined, so it did not
        // publish, so the container is unspent and another go is free. Reading
        // this as "may be live" would dead-letter every transient blip and
        // leave an operator chasing posts that were never made.
        $this->fakeApi(publish: Http::sequence()->push([], 503)->push(['id' => 'post-1']));

        $delivery = $this->publisher()->queue($this->post, $this->channel);
        $this->publisher()->attempt($delivery);

        $this->assertSame(DeliveryStatus::Retrying, $delivery->refresh()->status);

        $this->publisher()->attempt($delivery);

        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
        $this->assertSame('post-1', $this->journal()['published_root_id']);
        $this->assertCount(1, $this->calls('/threads'));
    }

    #[Test]
    public function a_publication_interrupted_between_the_two_calls_never_publishes_a_second_root(): void
    {
        // The failure this whole phase exists to prevent, staged exactly as §9
        // describes it: the container is accepted, the publish call does not
        // come back, and the worker tries again.
        Queue::fake();

        $this->fakeApi(
            container: Http::sequence()->push(['id' => 'container-1'])->push(['id' => 'container-2']),
            publish: Http::sequence()->push([], 503)->push(['id' => 'post-1'])->push(['id' => 'post-2']),
        );

        $delivery = $this->publisher()->queue($this->post, $this->channel);
        $this->publisher()->attempt($delivery);

        // Interrupted: the container is on the journal and nothing is live.
        $this->assertSame(DeliveryStatus::Retrying, $delivery->refresh()->status);
        $this->assertSame('container-1', $this->journal()['segments'][0]['container_id']);
        $this->assertNull($this->journal()['segments'][0]['published_id']);

        $this->publisher()->attempt($delivery);

        // One container, one root, and the retry sent only the half that was
        // unfinished.
        $this->assertCount(1, $this->calls('/threads'));
        $this->assertSame(['container-1', 'container-1'], array_column($this->calls('/threads_publish'), 'creation_id'));
        $this->assertSame('post-1', $this->journal()['segments'][0]['published_id']);
        $this->assertSame('post-1', $this->journal()['published_root_id']);

        // And a third pass over a settled delivery changes neither.
        $this->publisher()->attempt($delivery);

        $this->assertCount(1, $this->calls('/threads'));
        $this->assertSame('post-1', $this->journal()['published_root_id']);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
    }

    #[Test]
    public function the_journal_is_written_after_every_call_and_not_at_the_end(): void
    {
        Queue::fake();

        // The publish call never answers. If the container id were written when
        // the segment finished, it would be lost here — and the retry would
        // create a second container.
        $this->fakeApi(publish: Http::response([], 503));

        $delivery = $this->publisher()->queue($this->post, $this->channel);
        $this->publisher()->attempt($delivery);

        $this->assertSame('container-1', $this->journal()['segments'][0]['container_id']);
        $this->assertSame(DeliveryStatus::Retrying, $delivery->refresh()->status);
    }

    #[Test]
    public function a_retry_resumes_at_the_first_segment_without_a_published_id(): void
    {
        Queue::fake();

        $post = $this->aPost(
            ['The root, already out.', 'The second thought.', 'The third.'],
            [
                ['container_id' => 'container-1', 'published_id' => 'post-1'],
                [],
                [],
            ],
            ['published_root_id' => 'post-1'],
        );

        $this->fakeApi(
            container: Http::sequence()->push(['id' => 'container-2'])->push(['id' => 'container-3']),
            publish: Http::sequence()->push(['id' => 'post-2'])->push(['id' => 'post-3']),
        );

        $delivery = $this->publisher()->queue($post, $this->channel);
        $this->publisher()->attempt($delivery);

        $containers = $this->calls('/threads');

        // The published root is not touched, and its text never goes out again.
        $this->assertSame(['The second thought.', 'The third.'], array_column($containers, 'text'));
        $this->assertSame(['post-1', 'post-1'], array_column($containers, 'reply_to_id'));

        $journal = $this->journal($post);

        $this->assertSame('post-1', $journal['published_root_id']);
        $this->assertSame(['post-1', 'post-2', 'post-3'], array_column($journal['segments'], 'published_id'));
    }

    #[Test]
    public function a_gap_in_the_journal_is_resumed_by_name_and_not_by_counting(): void
    {
        Queue::fake();

        // The arrangement index arithmetic gets wrong. §9 says the retry
        // "продолжает с первого сегмента без `published_id`" — the first
        // segment *without an id*, not the first one past however many have
        // one. The journal is written after every call, so a hole in the
        // middle is a real state: a chain interrupted and partly resumed, or
        // two attempts that overlapped. Anything that slices from the count of
        // published segments republishes the third one's text and leaves the
        // second unpublished for good.
        $post = $this->aPost(
            ['The root, already out.', 'The middle one, lost between two writes.', 'The third, somehow out.'],
            [
                ['container_id' => 'container-1', 'published_id' => 'post-1'],
                [],
                ['container_id' => 'container-3', 'published_id' => 'post-3'],
            ],
            ['published_root_id' => 'post-1'],
        );

        $this->fakeApi(
            container: Http::response(['id' => 'container-2']),
            publish: Http::response(['id' => 'post-2']),
        );

        $delivery = $this->publisher()->queue($post, $this->channel);
        $this->publisher()->attempt($delivery);

        $containers = $this->calls('/threads');

        $this->assertCount(1, $containers);
        $this->assertSame('The middle one, lost between two writes.', $containers[0]['text']);
        $this->assertSame('post-1', $containers[0]['reply_to_id']);

        $journal = $this->journal($post);

        $this->assertSame(['post-1', 'post-2', 'post-3'], array_column($journal['segments'], 'published_id'));
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
    }

    #[Test]
    public function a_fresh_chain_answers_the_published_root_rather_than_standing_apart(): void
    {
        Queue::fake();

        // Without the root recorded as the chain is published, every segment
        // goes out with no `reply_to_id` — three root posts in the feed, three
        // units against the governor, and none of them a thread. The retry
        // path reads the root back off the journal and so never exercises
        // this; a first publish has nothing to read and has to remember.
        $post = $this->aPost(['The claim.', 'The evidence for it.']);

        $this->fakeApi(
            container: Http::sequence()->push(['id' => 'container-1'])->push(['id' => 'container-2']),
            publish: Http::sequence()->push(['id' => 'post-1'])->push(['id' => 'post-2']),
        );

        $this->publisher()->attempt($this->publisher()->queue($post, $this->channel));

        $containers = $this->calls('/threads');

        $this->assertCount(2, $containers);
        $this->assertArrayNotHasKey('reply_to_id', $containers[0]);

        // The *published* id of the first segment. Its container id is not a
        // post and answering it would be refused; null would be a second root.
        $this->assertSame('post-1', $containers[1]['reply_to_id'] ?? null);
        $this->assertSame('post-1', $this->journal($post)['published_root_id']);
    }

    #[Test]
    public function a_segment_holding_a_container_resumes_at_the_publish_call(): void
    {
        Queue::fake();

        $post = $this->aPost(
            ['A thought that was half published.'],
            [['container_id' => 'container-9']],
        );

        $this->fakeApi();

        $delivery = $this->publisher()->queue($post, $this->channel);
        $this->publisher()->attempt($delivery);

        // A second container would be a second post waiting to happen, and it
        // would spend an action from the budget of §2 to make it.
        $this->assertSame([], $this->calls('/threads'));
        $this->assertSame(['container-9'], array_column($this->calls('/threads_publish'), 'creation_id'));
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
    }

    #[Test]
    public function a_segment_with_an_image_asks_for_an_image_container(): void
    {
        Queue::fake();
        config()->set('filesystems.disks.public.url', 'https://cdn.example.test/storage');

        $post = $this->aPost(['A picture with a short caption.']);
        $asset = Asset::factory()->for($post, 'contentItem')->create(['path' => 'assets/salt.webp']);

        $this->journalAsset($post, $asset->getKey());
        $this->fakeApi();

        $this->publisher()->attempt($this->publisher()->queue($post, $this->channel));

        // §2: an image beats text by about 60%, so the media type is not an
        // afterthought — a segment with an asset is an IMAGE container and
        // Threads fetches the file itself.
        $container = $this->calls('/threads')[0];

        $this->assertSame('IMAGE', $container['media_type']);
        $this->assertSame('https://cdn.example.test/storage/assets/salt.webp', $container['image_url']);
    }

    #[Test]
    public function an_image_only_this_machine_can_reach_is_refused_before_it_is_sent(): void
    {
        Http::fake();
        config()->set('filesystems.disks.public.url', 'http://localhost/storage');

        $post = $this->aPost(['A picture Threads could never fetch.']);
        $asset = Asset::factory()->for($post, 'contentItem')->create(['path' => 'assets/salt.webp']);

        $this->journalAsset($post, $asset->getKey());

        $delivery = $this->publisher()->queue($post, $this->channel);

        // Threads fetches the image from its own side, so a development URL
        // fails there with a message the operator never sees. Better said here.
        Http::assertNothingSent();
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        $this->assertStringContainsString('public internet', (string) $delivery->error);
    }

    // ------------------------------------------------------------ the budget

    #[Test]
    public function the_publishing_ceiling_is_read_from_the_api_when_the_api_states_one(): void
    {
        $this->fakeApi(limit: Http::response([
            'data' => [['quota_usage' => 3, 'config' => ['quota_total' => 40, 'quota_duration' => 86_400]]],
        ]));

        // §9: "лимит читается у API, а не угадывается." The configured 250 is a
        // fallback for an API that does not answer, not the number in force.
        $this->assertSame(40, $this->limiter()->ceiling($this->channel, self::USER_ID, self::TOKEN));
        $this->assertSame(250, (int) config('social.threads.publish_actions_per_day'));
    }

    #[Test]
    public function the_configured_ceiling_is_used_only_when_the_api_states_none(): void
    {
        $this->fakeApi(limit: Http::response([], 500));

        $this->assertSame(250, $this->limiter()->ceiling($this->channel, self::USER_ID, self::TOKEN));
    }

    #[Test]
    public function the_budget_window_slides_rather_than_counting_to_a_reset(): void
    {
        // §2 says "скользящее окно", and the difference is not decorative: a
        // counter with a daily reset lets an account spend the whole budget at
        // 23:50 and the whole budget again at 00:10, which is exactly twice
        // what the platform allows. So the window is a list of timestamps, and
        // each one has to age out on its own schedule.
        $start = Carbon::parse('2026-03-01 23:50:00');
        Carbon::setTestNow($start);

        $this->fakeApi(limit: Http::response([
            'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 5, 'quota_duration' => 86_400]]],
        ]));

        $this->limiter()->spend($this->channel, 3, self::USER_ID, self::TOKEN);

        Carbon::setTestNow($start->copy()->addSeconds(100));

        $this->limiter()->spend($this->channel, 2, self::USER_ID, self::TOKEN);

        $this->assertSame(0, $this->limiter()->remaining($this->channel, self::USER_ID, self::TOKEN));
        $this->assertFalse($this->limiter()->allows($this->channel, 1, self::USER_ID, self::TOKEN));

        // Far enough on that the first three actions have left the window and
        // the last two have not. A plain counter would still say five.
        Carbon::setTestNow($start->copy()->addSeconds(86_450));

        $this->assertSame(3, $this->limiter()->remaining($this->channel, self::USER_ID, self::TOKEN));
        $this->assertTrue($this->limiter()->allows($this->channel, 3, self::USER_ID, self::TOKEN));
        $this->assertFalse($this->limiter()->allows($this->channel, 4, self::USER_ID, self::TOKEN));
    }

    #[Test]
    public function the_window_says_exactly_when_the_next_actions_fit(): void
    {
        // The reason for keeping timestamps rather than a count: the moment
        // enough of the oldest actions age out is a real answer, and it is the
        // retry time a deferred publication wants. "Some time in the future"
        // is what a backoff ladder already offers.
        $start = Carbon::parse('2026-03-01 23:50:00');
        Carbon::setTestNow($start);

        $this->fakeApi(limit: Http::response([
            'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 5, 'quota_duration' => 86_400]]],
        ]));

        $this->limiter()->spend($this->channel, 3, self::USER_ID, self::TOKEN);

        Carbon::setTestNow($start->copy()->addSeconds(100));

        $this->limiter()->spend($this->channel, 2, self::USER_ID, self::TOKEN);

        // One action needs the oldest of the five to expire; three need the
        // third-oldest, which was spent in the same instant; a fourth has to
        // wait for the pair spent a hundred seconds later.
        $this->assertSame(
            $start->getTimestamp() + 86_400,
            $this->limiter()->availableAt($this->channel, 1, self::USER_ID, self::TOKEN)->getTimestamp(),
        );
        $this->assertSame(
            $start->getTimestamp() + 86_400,
            $this->limiter()->availableAt($this->channel, 3, self::USER_ID, self::TOKEN)->getTimestamp(),
        );
        $this->assertSame(
            $start->getTimestamp() + 86_500,
            $this->limiter()->availableAt($this->channel, 4, self::USER_ID, self::TOKEN)->getTimestamp(),
        );
    }

    #[Test]
    public function a_post_over_the_budget_retries_later_instead_of_dead_lettering(): void
    {
        // Queue::fake because the deferral is deliberately *not* a rung of the
        // ladder: the row keeps its attempt count and is re-dispatched for when
        // the window frees, and the sync driver would run that immediately.
        Queue::fake();

        $this->fakeApi(limit: Http::response([
            'data' => [['quota_usage' => 250, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]],
        ]));

        $delivery = $this->publisher()->queue($this->post, $this->channel);
        $this->publisher()->attempt($delivery);
        $delivery->refresh();

        // The post is fine; the window is full.
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        $this->assertStringContainsString('budget', (string) $delivery->error);
        $this->assertTrue($delivery->next_attempt_at?->isFuture());
        $this->assertSame([], $this->calls('/threads'));
        $this->assertSame([], $this->calls('/threads_publish'));
    }

    #[Test]
    public function a_publication_deferred_for_a_full_window_goes_out_when_the_window_frees(): void
    {
        Queue::fake();

        $start = Carbon::parse('2026-03-02 09:00:00');
        Carbon::setTestNow($start);

        $this->fakeApi(limit: Http::sequence()
            ->push(['data' => [['quota_usage' => 250, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]]])
            ->push(['data' => [['quota_usage' => 0, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]]]));

        $delivery = $this->publisher()->queue($this->post, $this->channel);
        $this->publisher()->attempt($delivery);
        $delivery->refresh();

        // A wait, counted as a wait: the ladder is untouched and the row says
        // how many times it has been put off, which is the only durable record
        // that would let anyone notice it never stops being put off.
        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        $this->assertSame(1, $delivery->deferrals);

        // Past the half hour the ceiling read is cached for, so the limiter
        // asks again and hears that the account has room.
        Carbon::setTestNow($start->copy()->addHour());

        $this->publisher()->attempt($delivery);
        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(1, $delivery->deferrals);
        $this->assertCount(1, $this->calls('/threads'));
        $this->assertSame('post-1', $this->journal()['published_root_id']);
    }

    #[Test]
    public function a_window_that_never_frees_dead_letters_rather_than_deferring_forever(): void
    {
        Queue::fake();

        // §2's budget is per user, and the limiter reads the platform's own
        // `quota_usage` precisely because something else can be publishing as
        // this account. Pinned at the ceiling, it is a state that never clears
        // on its own — and a deferral spends no rung of the ladder, so nothing
        // else would ever end this row. It would sit in `Retrying`, invisible:
        // the panel offers replay for a dead letter and nothing for a post that
        // has been an hour from going out since Tuesday.
        $this->fakeApi(limit: Http::response([
            'data' => [['quota_usage' => 250, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]],
        ]));

        $start = Carbon::parse('2026-03-02 09:00:00');
        $delivery = $this->publisher()->queue($this->post, $this->channel);

        for ($round = 1; $round <= 8; $round++) {
            Carbon::setTestNow($start->copy()->addHours($round));
            $this->publisher()->attempt($delivery);
            $delivery->refresh();
        }

        $this->assertSame(DeliveryStatus::Retrying, $delivery->status);
        $this->assertSame(8, $delivery->deferrals);

        Carbon::setTestNow($start->copy()->addHours(9));
        $this->publisher()->attempt($delivery);
        $delivery->refresh();

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        $this->assertNull($delivery->next_attempt_at);

        // The reason has to separate this from a refusal. Nothing was sent, so
        // no post is live and nobody has to go and look at the account; what
        // needs attention is the account's budget.
        $this->assertStringContainsString('budget', (string) $delivery->error);
        $this->assertStringContainsString('Nothing was sent', (string) $delivery->error);
        $this->assertStringNotContainsString('Threads refused', (string) $delivery->error);

        // And the ladder was never spent on it, at any point.
        $this->assertSame(0, $delivery->attempts);
        $this->assertSame([], $this->calls('/threads'));
        $this->assertSame([], $this->calls('/threads_publish'));
    }

    #[Test]
    public function a_chain_costs_two_actions_a_segment_rather_than_one_a_post(): void
    {
        Queue::fake();

        $post = $this->aPost(['One.', 'Two.']);

        $this->fakeApi(
            container: Http::sequence()->push(['id' => 'container-1'])->push(['id' => 'container-2']),
            publish: Http::sequence()->push(['id' => 'post-1'])->push(['id' => 'post-2']),
        );

        $this->publisher()->attempt($this->publisher()->queue($post, $this->channel));

        // §2 counts actions, and a container and a publish are two of them. A
        // limiter counting posts would let a chain spend twice what it was
        // shown.
        $this->assertSame(
            246,
            $this->limiter()->remaining($this->channel, self::USER_ID, self::TOKEN),
        );
    }

    // ---------------------------------------------------------- what stops it

    #[Test]
    public function a_rejected_credential_marks_the_integration_broken_and_says_so(): void
    {
        Queue::fake();

        // Meta's other spelling of a dead token: a 400 carrying OAuth subcode
        // 190. The status line alone would read as "your request was wrong".
        $this->fakeApi(container: Http::response([
            'error' => ['message' => 'Error validating access token: the session has been invalidated.', 'code' => 190],
        ], 400));

        // Asked of the provider's own question rather than of
        // `ProjectIntegration::isUsable()`, which wants a `refresh_token`.
        // Threads has no second credential, so that one is false before this
        // test runs anything and would assert nothing.
        $connection = app(ThreadsConnection::class);

        $this->assertTrue($connection->isUsable($this->integration));

        $delivery = $this->publisher()->queue($this->post, $this->channel);
        $this->publisher()->attempt($delivery);
        $delivery->refresh();

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        $this->assertSame(401, $delivery->response_code);
        $this->assertStringContainsString('Reconnect', (string) $delivery->error);
        $this->assertNotNull($this->integration->refresh()->failure_reason);
        $this->assertFalse($connection->isUsable($this->integration));
    }

    #[Test]
    public function a_bare_401_is_a_dead_credential_even_with_no_error_code(): void
    {
        Queue::fake();

        // The other arm of the same question, and the one the platform uses in
        // its other flows: a plain 401 with nothing in the body to classify.
        // Read as an ordinary refusal it would dead-letter with a message that
        // sends the operator to look at the post instead of at the connection,
        // and would leave the integration looking healthy for the next post to
        // fail the same way.
        $this->fakeApi(container: Http::response([], 401));

        $connection = app(ThreadsConnection::class);

        $this->assertTrue($connection->isUsable($this->integration));

        $delivery = $this->publisher()->queue($this->post, $this->channel);
        $this->publisher()->attempt($delivery);
        $delivery->refresh();

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        $this->assertSame(401, $delivery->response_code);
        $this->assertStringContainsString('Reconnect', (string) $delivery->error);
        $this->assertFalse($connection->isUsable($this->integration->refresh()));
        $this->assertSame([], $this->calls('/threads_publish'));
    }

    #[Test]
    public function an_installation_with_no_threads_app_dead_letters_and_sends_nothing(): void
    {
        Http::fake();
        config()->set('services.threads.client_id', null);
        config()->set('services.threads.client_secret', null);

        $delivery = $this->publisher()->queue($this->post, $this->channel);

        Http::assertNothingSent();
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        $this->assertStringContainsString('THREADS_APP_ID', (string) $delivery->error);
    }

    #[Test]
    public function a_project_with_no_connected_account_dead_letters_and_sends_nothing(): void
    {
        Http::fake();
        $this->integration->delete();

        $delivery = $this->publisher()->queue($this->post, $this->channel);

        Http::assertNothingSent();
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        $this->assertStringContainsString('Connect', (string) $delivery->error);
    }

    #[Test]
    public function text_over_five_hundred_characters_is_refused_rather_than_retried(): void
    {
        Http::fake();

        $post = $this->aPost([str_repeat('a', 501)]);

        $delivery = $this->publisher()->queue($post, $this->channel);

        // §2's limit is the platform's own. Walking the ladder over it would
        // spend five attempts and the budget to be told the same thing.
        Http::assertNothingSent();
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        $this->assertStringContainsString('501 characters', (string) $delivery->error);
    }

    #[Test]
    public function the_platform_being_unwell_walks_the_ladder_and_then_dead_letters(): void
    {
        $this->fakeApi(container: Http::response([], 503));

        $delivery = $this->publisher()->queue($this->post, $this->channel)->refresh();

        // The shared mechanics of §9, unchanged: the sync driver runs each
        // delayed retry at once, so queueing walks the whole published ladder.
        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->status);
        $this->assertSame(count((array) config('publishing.backoff')), $delivery->attempts);
        $this->assertCount(5, $this->calls('/threads'));
    }

    // ---------------------------------------------------------------- replay

    #[Test]
    public function replaying_a_finished_publication_publishes_nothing_again(): void
    {
        $this->fakeApi();

        $first = $this->publisher()->queue($this->post, $this->channel);
        $this->assertSame(DeliveryStatus::Delivered, $first->refresh()->status);

        $replay = $this->publisher()->replay($first);

        // A replay of a two-phase publication can only mean "resume", and there
        // is nothing to resume. Delivered, honestly, having sent nothing.
        $this->assertNotSame($first->delivery_id, $replay->delivery_id);
        $this->assertSame(DeliveryStatus::Delivered, $replay->refresh()->status);
        $this->assertCount(1, $this->calls('/threads'));
        $this->assertCount(1, $this->calls('/threads_publish'));
        $this->assertSame('post-1', $this->journal()['published_root_id']);
    }

    #[Test]
    public function replaying_a_half_finished_publication_finishes_it(): void
    {
        $this->fakeApi();

        $post = $this->aPost(
            ['Half published, and waiting.'],
            [['container_id' => 'container-9']],
        );

        $dead = WebhookDelivery::query()->create([
            'channel_id' => $this->channel->getKey(),
            'content_item_id' => $post->getKey(),
            'status' => DeliveryStatus::DeadLetter->value,
            'payload_snapshot' => ['event' => 'content.published'],
        ]);

        $replay = $this->publisher()->replay($dead);

        $this->assertSame(DeliveryStatus::Delivered, $replay->refresh()->status);
        $this->assertSame([], $this->calls('/threads'));
        $this->assertSame('post-1', $this->journal($post)['published_root_id']);
    }

    // ------------------------------------------------------------------ ping

    #[Test]
    public function a_ping_proves_the_credential_and_verifies_the_channel(): void
    {
        $this->fakeApi(read: Http::response(['id' => self::USER_ID, 'username' => 'engine']));

        $channel = Channel::factory()->threads()->create(['verified_at' => null]);

        $delivery = $this->publisher()->ping($channel, $this->project);

        // Not a signed POST to an endpoint — there is no endpoint. Proving the
        // credential is the test, and the wizard still gets its row and its
        // verified channel.
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status);
        $this->assertNotNull($channel->refresh()->verified_at);
        $this->assertNull($delivery->content_item_id);
    }

    #[Test]
    public function a_ping_with_a_dead_token_leaves_the_channel_unverified(): void
    {
        $this->fakeApi(read: Http::response(['error' => ['message' => 'Token expired.', 'code' => 190]], 400));

        $channel = Channel::factory()->threads()->create(['verified_at' => null]);

        $delivery = $this->publisher()->ping($channel, $this->project);

        $this->assertSame(DeliveryStatus::DeadLetter, $delivery->refresh()->status);
        $this->assertNull($channel->refresh()->verified_at);
        $this->assertNotNull($this->integration->refresh()->failure_reason);
    }

    // ----------------------------------------------------------------- setup

    /**
     * A post with its §3 journal already on it.
     *
     * @param  list<string>  $texts
     * @param  list<array<string, string|null>>  $journal
     * @param  array<string, mixed>  $payload
     */
    private function aPost(array $texts, array $journal = [], array $payload = []): ContentItem
    {
        $segments = [];

        foreach ($texts as $position => $text) {
            $segments[] = [
                'text' => $text,
                'asset_id' => null,
                'container_id' => $journal[$position]['container_id'] ?? null,
                'published_id' => $journal[$position]['published_id'] ?? null,
                ...($journal[$position] ?? []),
            ];
        }

        return ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'state' => ContentItemState::Approved,
            'channel_type' => ChannelType::Threads->value,
            'channel_payload' => [
                'segments' => $segments,
                'link_attachment' => null,
                'reply_control' => 'everyone',
                'angle' => 'question',
                'permalink' => null,
                'published_root_id' => null,
                ...$payload,
            ],
        ]);
    }

    /** Hang an image off the first segment of a post's journal. */
    private function journalAsset(ContentItem $post, string $assetId): void
    {
        $payload = (array) $post->channel_payload;
        $payload['segments'][0]['asset_id'] = $assetId;

        $post->forceFill(['channel_payload' => $payload])->save();
    }

    /**
     * The four addresses the adapter knows, in match order.
     *
     * `Http::fake()` merges stubs rather than replacing them, so a test that
     * wants a different answer passes it here instead of calling fake() twice.
     */
    private function fakeApi(mixed $container = null, mixed $publish = null, mixed $limit = null, mixed $read = null): void
    {
        Http::fake([
            'graph.threads.net/v1.0/*/threads_publishing_limit*' => $limit ?? Http::response([
                'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]],
            ]),
            'graph.threads.net/v1.0/*/threads_publish' => $publish ?? Http::response(['id' => 'post-1']),
            'graph.threads.net/v1.0/*/threads' => $container ?? Http::response(['id' => 'container-1']),
            'graph.threads.net/v1.0/*' => $read ?? Http::response([
                'id' => 'post-1',
                'permalink' => 'https://www.threads.net/@engine/post/AAA',
            ]),
        ]);
    }

    /**
     * The bodies sent to one endpoint, in order.
     *
     * Counting by endpoint rather than in total: the adapter also reads the
     * budget and the permalink, and a bare `assertSentCount` would make every
     * assertion here about the wrong thing.
     *
     * @return list<array<string, mixed>>
     */
    private function calls(string $endpoint): array
    {
        /** @var list<array<string, mixed>> $bodies */
        $bodies = Http::recorded(static fn (Request $request): bool => str_ends_with(
            (string) parse_url($request->url(), PHP_URL_PATH),
            $endpoint,
        ))
            ->map(static fn (array $pair): array => $pair[0]->data())
            ->values()
            ->all();

        return $bodies;
    }

    /**
     * The journal as it is stored, read back from the row rather than from an
     * in-memory model — the point being that it survived the write.
     *
     * @return array<string, mixed>
     */
    private function journal(?ContentItem $post = null): array
    {
        $stored = ($post ?? $this->post)->fresh()?->channel_payload;

        return is_array($stored) ? $stored : [];
    }

    private function publisher(): ThreadsPublisher
    {
        return app(ThreadsPublisher::class);
    }

    private function limiter(): ThreadsRateLimiter
    {
        return app(ThreadsRateLimiter::class);
    }
}
