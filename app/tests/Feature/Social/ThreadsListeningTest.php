<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Integrations\Exceptions\ThreadsRefused;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Integrations\Feeds\FeedReader;
use App\Integrations\Threads\ThreadsConnection;
use App\Integrations\Threads\ThreadsSearch;
use App\Integrations\Threads\ThreadsSearchBudget;
use App\Integrations\Threads\ThreadsSearchMode;
use App\Integrations\Threads\ThreadsSearchOutcome;
use App\Models\Channel;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\Signal;
use App\Models\User;
use App\Social\Jobs\DraftInteractionReplyJob;
use App\Support\Tenancy\CurrentProject;
use Database\Factories\ProjectIntegrationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * §4.1 — the intake edges of the listening contour.
 *
 * The contour §4.1 says pays for itself even if nothing is ever published, so
 * most of what is asserted here is about it *not* falling over: an empty answer
 * that is not a failure, a missing scope that is not a failure, a broken feed
 * that does not take the run with it, a redelivered event that does not become
 * a second conversation.
 *
 * §11.2 gets the most attention, because it is the one place where three
 * different situations arrive looking identical. The tests below check them
 * from the outside — what the caller gets, what is written, how many requests
 * went out and what the budget was charged — rather than reaching into the
 * adapter, because a distinction only counts if a caller can act on it.
 *
 * Nothing reaches the network. `Http::preventStrayRequests()` is on globally.
 */
final class ThreadsListeningTest extends TestCase
{
    use RefreshDatabase;

    /** What {@see ProjectIntegrationFactory::threads()} connects. */
    private const string USER_ID = '17841400000000000';

    private const string WEBHOOK = '/api/threads/webhook';

    private const string SECRET = 'threads-webhook-secret';

    private const string VERIFY_TOKEN = 'threads-verify-token';

    private Project $project;

    private Channel $channel;

    private ProjectIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        config()->set('services.threads.client_id', 'threads-app-id');
        config()->set('services.threads.client_secret', 'threads-app-secret');
        config()->set('services.threads.webhook_secret', self::SECRET);
        config()->set('services.threads.webhook_verify_token', self::VERIFY_TOKEN);

        $this->channel = Channel::factory()->threads()->create(['verified_at' => now()]);
        $this->integration = ProjectIntegration::factory()->threads()->create();
    }

    /**
     * The clock is released here and not at the end of the tests that freeze it.
     *
     * Two of them used to call `setTestNow()` on their last line, which works
     * exactly until an assertion above it fails: PHPUnit stops there, the
     * frozen clock survives into every test that runs after, and the failure
     * that gets reported is somebody else's.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------- §11.2, outcome one

    #[Test]
    public function an_empty_answer_for_a_sensitive_word_is_not_a_failure_and_costs_nothing(): void
    {
        // §11.2: "по «чувствительным» словам API молча отдаёт пустой массив".
        Http::fake(['graph.threads.net/*' => Http::response(['data' => []])]);

        $answer = app(ThreadsSearch::class)->search($this->project, 'ceasefire');

        // Not an exception, and not something a caller could read as one.
        $this->assertSame(ThreadsSearchOutcome::Nothing, $answer->outcome);
        $this->assertSame([], $answer->posts);
        $this->assertFalse($answer->isDegraded());
        $this->assertFalse($answer->isBudgetSpent());

        // Asked exactly once. A retry ladder over a word the platform has
        // decided to be quiet about walks itself to the daily ceiling.
        Http::assertSentCount(1);

        // §2: "пустые ответы не считаются".
        $this->assertSame(
            app(ThreadsSearchBudget::class)->ceiling(),
            app(ThreadsSearchBudget::class)->remaining($this->project),
        );

        $this->assertSame(0, Signal::acrossProjects()->count());
        $this->assertSame(0, Interaction::acrossProjects()->count());
    }

    // ------------------------------------------------- §11.2, outcome two

    #[Test]
    public function a_missing_keyword_search_scope_degrades_to_our_own_posts(): void
    {
        $this->fakeRefusedScope();

        $answer = app(ThreadsSearch::class)->search($this->project, 'limescale');

        $this->assertSame(ThreadsSearchOutcome::OwnPostsOnly, $answer->outcome);
        $this->assertTrue($answer->isDegraded());

        // Working on less, not stopped: the term still matched one of ours, and
        // the post that did not mention it was left out.
        $this->assertCount(1, $answer->posts);
        $this->assertSame('own-1', $answer->posts[0]->id);
    }

    #[Test]
    public function the_missing_scope_is_recorded_where_an_operator_can_see_it_and_not_in_failure_reason(): void
    {
        $this->fakeRefusedScope();

        app(ThreadsSearch::class)->search($this->project, 'limescale');

        $flag = $this->integration->refresh()->config[ThreadsSearch::DEGRADED_FLAG] ?? null;

        $this->assertIsArray($flag);
        $this->assertFalse($flag['granted']);
        $this->assertNotEmpty($flag['observed_at']);

        // `failure_reason` means "this connection cannot answer", and every
        // other caller reads it that way. A missing optional scope written
        // there would stop publishing and insights along with listening.
        $this->assertNull($this->integration->failure_reason);
        // Still a working connection by the only reading that applies to this
        // provider — `ProjectIntegration::isUsable()` asks for a refresh token,
        // which is Google's shape and not Threads'.
        $this->assertTrue(app(ThreadsConnection::class)->isUsable($this->integration->refresh()));
    }

    #[Test]
    public function the_missing_scope_is_not_rediscovered_by_a_failing_call_every_hour(): void
    {
        $this->fakeRefusedScope();
        Carbon::setTestNow('2026-08-09 09:00:00');

        app(ThreadsSearch::class)->search($this->project, 'limescale');

        $observedAt = $this->integration->refresh()->config[ThreadsSearch::DEGRADED_FLAG]['observed_at'];

        Carbon::setTestNow('2026-08-09 10:00:00');

        $answer = app(ThreadsSearch::class)->search($this->project, 'limescale');

        // Still degraded, still returning our own posts — and the platform was
        // not asked to refuse the search a second time.
        $this->assertSame(ThreadsSearchOutcome::OwnPostsOnly, $answer->outcome);
        $this->assertCount(1, $this->requestsTo('keyword_search'));
        $this->assertCount(2, $this->requestsTo('/threads'));

        // The record is the first one, untouched. A second write would mean a
        // second probe, and a second line in the operator's log every hour.
        $this->assertSame(
            $observedAt,
            $this->integration->refresh()->config[ThreadsSearch::DEGRADED_FLAG]['observed_at'],
        );
    }

    #[Test]
    public function a_refusal_recognised_only_by_its_wording_is_rechecked_the_next_day(): void
    {
        // The other branch of the scope check, and the realistic one: Meta
        // answers a missing permission with a 400 at least as readily as with a
        // 403, so the wording is all there is to go on. Only the 403 branch was
        // ever faked here, which is how the hint list came to hold Meta's
        // generic 400 without anything noticing.
        Carbon::setTestNow('2026-08-09 09:00:00');
        $this->fakeWordedRefusal();

        $answer = app(ThreadsSearch::class)->search($this->project, 'limescale');

        $this->assertSame(ThreadsSearchOutcome::OwnPostsOnly, $answer->outcome);
        $this->assertSame(
            'message',
            $this->integration->refresh()->config[ThreadsSearch::DEGRADED_FLAG]['evidence'],
        );

        // Believed for a day and not a week. Twenty-three hours on, nothing is
        // probed…
        Carbon::setTestNow('2026-08-10 08:00:00');
        app(ThreadsSearch::class)->search($this->project, 'limescale');
        $this->assertCount(1, $this->requestsTo('keyword_search'));

        // …and past the day the platform is asked again, because a guess about
        // wording is not evidence worth a week of listening to ourselves.
        Carbon::setTestNow('2026-08-10 10:00:00');
        app(ThreadsSearch::class)->search($this->project, 'limescale');
        $this->assertCount(2, $this->requestsTo('keyword_search'));
    }

    #[Test]
    public function a_refusal_the_platform_answered_with_403_is_believed_for_the_week(): void
    {
        // The contrast that makes the day above mean something: a 403 is the
        // platform answering the question we asked, so it is not re-probed
        // hourly *or* daily.
        Carbon::setTestNow('2026-08-09 09:00:00');
        $this->fakeRefusedScope();

        app(ThreadsSearch::class)->search($this->project, 'limescale');

        Carbon::setTestNow('2026-08-11 09:00:00');

        $answer = app(ThreadsSearch::class)->search($this->project, 'limescale');

        $this->assertSame(ThreadsSearchOutcome::OwnPostsOnly, $answer->outcome);
        $this->assertCount(1, $this->requestsTo('keyword_search'));
    }

    #[Test]
    public function metas_generic_400_does_not_degrade_the_project_for_a_week(): void
    {
        // Meta's answer to a path that does not exist or an object id that no
        // longer does — and it says "missing permissions" in passing, which is
        // why matching on the word `permission` was never narrow enough. Read
        // as a scope refusal, one typo silently switched the contour to reading
        // our own posts with a single `notice` as the symptom.
        Http::fake(['graph.threads.net/*' => Http::response(['error' => [
            'message' => "Unsupported get request. Object with ID 'keyword_search' does not exist, "
                .'cannot be loaded due to missing permissions, or does not support this operation. '
                .'Please read the Graph API documentation.',
            'code' => 100,
        ]], 400)]);

        try {
            app(ThreadsSearch::class)->search($this->project, 'limescale');

            $this->fail('A generic 400 was swallowed as a missing scope.');
        } catch (ThreadsRefused) {
            // Where it belongs: `FetchSearch` logs the term and asks the next.
        }

        $this->assertArrayNotHasKey(
            ThreadsSearch::DEGRADED_FLAG,
            $this->integration->refresh()->config,
        );
    }

    #[Test]
    public function the_degraded_path_is_charged_for_the_page_the_platform_sent(): void
    {
        $this->fakeRefusedScope();

        // A term none of our own posts mentions. The platform still answered
        // with a full page and we threw it away here — §2's exemption is for an
        // *empty answer*, so this is a request, and a degraded project issuing
        // sixteen of them an hour used to count almost none of them.
        $answer = app(ThreadsSearch::class)->search($this->project, 'photovoltaics');

        $this->assertSame(ThreadsSearchOutcome::OwnPostsOnly, $answer->outcome);
        $this->assertSame([], $answer->posts);
        $this->assertCount(1, $this->requestsTo('/threads'));

        $budget = app(ThreadsSearchBudget::class);

        // Two: the refused `keyword_search` and the own-posts page it fell back
        // to. Both reached the platform, and §2 exempts an empty answer rather
        // than an unhappy one — §5 says недобор допустим, перебор нет, so a
        // request whose cost cannot be read back is counted.
        $this->assertSame($budget->ceiling() - 2, $budget->remaining($this->project));
    }

    #[Test]
    public function a_degraded_caller_reads_its_own_posts_once_however_many_terms_it_asks_about(): void
    {
        $this->fakeRefusedScope();

        $search = app(ThreadsSearch::class);

        foreach (['limescale', 'hard water', 'kettle'] as $term) {
            $search->search($this->project, $term);
        }

        // The request does not depend on the term — only the containment filter
        // does — so one page serves the whole run. Sixteen identical `GET
        // /{user-id}/threads` an hour is 384 a day against §2's 2 200.
        $this->assertCount(1, $this->requestsTo('/threads'));

        $budget = app(ThreadsSearchBudget::class);

        // Two, and only two, however many terms are asked about: the one
        // refusal that discovered the missing scope, and the one own-posts page
        // that serves every term after it.
        $this->assertSame($budget->ceiling() - 2, $budget->remaining($this->project));
    }

    #[Test]
    public function a_connection_whose_granted_scopes_never_included_search_never_probes_at_all(): void
    {
        // The operator connected before the scope was requested, or declined
        // it. Known without spending a request to find out.
        $this->integration->forceFill(['scopes' => ['threads_basic', 'threads_content_publish']])->save();

        $this->fakeOwnPosts();

        $answer = app(ThreadsSearch::class)->search($this->project, 'limescale');

        $this->assertSame(ThreadsSearchOutcome::OwnPostsOnly, $answer->outcome);
        $this->assertCount(0, $this->requestsTo('keyword_search'));
    }

    // ----------------------------------------------- §11.2, outcome three

    #[Test]
    public function a_transport_failure_is_retryable_and_is_none_of_the_other_two(): void
    {
        Http::fake(['graph.threads.net/*' => Http::response(['error' => ['message' => 'Sorry, something went wrong.']], 503)]);

        // Retryable, and it arrives as an exception rather than as a value —
        // which is exactly what makes the empty answer above unmistakable for
        // a failure. There is no `ThreadsSearchOutcome` that means "broken".
        $this->expectException(ThreadsUnavailable::class);

        app(ThreadsSearch::class)->search($this->project, 'limescale');
    }

    #[Test]
    public function a_terminal_refusal_that_is_not_about_permissions_is_not_swallowed_as_degradation(): void
    {
        Http::fake([
            'graph.threads.net/*' => Http::response(['error' => ['message' => 'The parameter q is required.']], 400),
        ]);

        $this->expectException(ThreadsRefused::class);

        app(ThreadsSearch::class)->search($this->project, 'limescale');
    }

    // ------------------------------------------------------- the budget

    #[Test]
    public function the_search_budget_stops_searching_at_the_ceiling_rather_than_throwing(): void
    {
        config()->set('social.threads.search_requests_per_day', 2);

        Http::fake(['graph.threads.net/*' => Http::response(['data' => [$this->apiPost('found-1', 'limescale in Lisbon')]])]);

        $search = app(ThreadsSearch::class);

        $this->assertSame(ThreadsSearchOutcome::Searched, $search->search($this->project, 'a')->outcome);
        $this->assertSame(ThreadsSearchOutcome::Searched, $search->search($this->project, 'b')->outcome);

        Http::fake(['graph.threads.net/*' => Http::response(['data' => []])]);

        $answer = $search->search($this->project, 'c');

        // A stop, not a throw: §5 says the budget is a ceiling and undershooting
        // is allowed. And nothing went out — the ceiling is checked before the
        // request, so a full window costs not even the round trip.
        $this->assertSame(ThreadsSearchOutcome::BudgetSpent, $answer->outcome);
        $this->assertTrue($answer->isBudgetSpent());
        Http::assertNothingSent();

        $this->assertSame(0, app(ThreadsSearchBudget::class)->remaining($this->project));
    }

    #[Test]
    public function the_budget_window_slides_rather_than_resetting_at_midnight(): void
    {
        config()->set('social.threads.search_requests_per_day', 1);

        $budget = app(ThreadsSearchBudget::class);

        Carbon::setTestNow('2026-08-09 23:50:00');
        $budget->spend($this->project);
        $this->assertFalse($budget->allows($this->project));

        // A counter with a midnight reset would let the whole ceiling go again
        // twenty minutes later. The platform's window would not.
        Carbon::setTestNow('2026-08-10 00:10:00');
        $this->assertFalse($budget->allows($this->project));

        Carbon::setTestNow('2026-08-10 23:51:00');
        $this->assertTrue($budget->allows($this->project));
    }

    #[Test]
    public function two_projects_on_one_threads_account_share_one_budget(): void
    {
        config()->set('social.threads.search_requests_per_day', 2);

        // An agency running a brand's site and its shop from one login. §2's
        // limit is per *user*, and the platform enforces it once — so a budget
        // keyed on the project would hand each of these a full 2 200 while the
        // account has 2 200 between them, and the engine would keep asking and
        // read the resulting 429s as an outage.
        $other = Project::factory()->create();
        app(CurrentProject::class)->run($other, function (): void {
            ProjectIntegration::factory()->threads()->create();
        });

        $budget = app(ThreadsSearchBudget::class);

        $budget->spend($this->project, 2);

        $this->assertFalse($budget->allows($this->project));
        $this->assertFalse($budget->allows($other));
        $this->assertSame(0, $budget->remaining($other));
    }

    #[Test]
    public function two_projects_on_different_accounts_do_not_share_one(): void
    {
        config()->set('social.threads.search_requests_per_day', 2);

        $other = Project::factory()->create();
        app(CurrentProject::class)->run($other, function (): void {
            ProjectIntegration::factory()->threads()->create([
                'config' => ['user_id' => '17841400000000001', 'username' => 'someone-else'],
            ]);
        });

        $budget = app(ThreadsSearchBudget::class);

        $budget->spend($this->project, 2);

        // The mirror of the test above, and the reason the key is the account
        // id rather than something coarser: two accounts are two ceilings.
        $this->assertFalse($budget->allows($this->project));
        $this->assertTrue($budget->allows($other));
    }

    #[Test]
    public function a_project_with_no_threads_connection_is_told_so_rather_than_failing(): void
    {
        $this->integration->delete();

        Http::fake(['graph.threads.net/*' => Http::response(['data' => []])]);

        $answer = app(ThreadsSearch::class)->search($this->project, 'limescale');

        $this->assertSame(ThreadsSearchOutcome::NotConnected, $answer->outcome);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_search_returns_typed_posts_rather_than_the_raw_answer(): void
    {
        Http::fake(['graph.threads.net/*' => Http::response(['data' => [
            $this->apiPost('found-1', 'Why does limescale come back so fast?'),
            // No id: unrepliable and undedupable, so it is dropped rather than
            // taking the rest of the page with it.
            ['text' => 'orphaned'],
        ]])]);

        $answer = app(ThreadsSearch::class)->search(
            $this->project,
            'limescale',
            ThreadsSearchMode::Tag,
        );

        $this->assertSame(ThreadsSearchOutcome::Searched, $answer->outcome);
        $this->assertCount(1, $answer);
        $this->assertSame('Why does limescale come back so fast?', $answer->posts[0]->text);
        $this->assertSame('asker', $answer->posts[0]->username);
        $this->assertSame('2026-08-09T08:00:00+00:00', $answer->posts[0]->postedAt?->toIso8601String());

        $sent = $this->requestsTo('keyword_search')[0];

        $this->assertStringContainsString('search_mode=TAG', $sent->url());
        $this->assertStringContainsString('search_type=RECENT', $sent->url());
    }

    // ------------------------------------------------- the handshake (GET)

    #[Test]
    public function the_subscription_handshake_echoes_the_challenge_as_a_bare_string(): void
    {
        $response = $this->get(self::WEBHOOK.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => self::VERIFY_TOKEN,
            'hub.challenge' => '1158201444',
        ]));

        $response->assertOk();

        // Bare. Meta compares the body to the challenge it sent, so a JSON
        // wrapper or a trailing newline fails the subscription.
        $this->assertSame('1158201444', $response->getContent());
    }

    #[Test]
    public function the_handshake_refuses_a_wrong_verify_token(): void
    {
        $this->get(self::WEBHOOK.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'not-the-token',
            'hub.challenge' => '1158201444',
        ]))->assertForbidden();
    }

    #[Test]
    public function the_handshake_refuses_a_mode_that_is_not_subscribe(): void
    {
        $this->get(self::WEBHOOK.'?'.http_build_query([
            'hub.mode' => 'unsubscribe',
            'hub.verify_token' => self::VERIFY_TOKEN,
            'hub.challenge' => '1158201444',
        ]))->assertForbidden();
    }

    #[Test]
    public function the_handshake_refuses_when_the_installation_has_no_verify_token(): void
    {
        config()->set('services.threads.webhook_verify_token', null);

        $this->get(self::WEBHOOK.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => '',
            'hub.challenge' => '1158201444',
        ]))->assertStatus(503);
    }

    // ------------------------------------------------------ the event (POST)

    #[Test]
    public function an_unsigned_body_is_refused(): void
    {
        $this->postEvent($this->reply('reply-1'), signature: 'sha256=deadbeef')->assertStatus(401);

        $this->assertSame(0, Interaction::acrossProjects()->count());
    }

    #[Test]
    public function an_installation_with_no_webhook_secret_refuses_rather_than_accepting(): void
    {
        config()->set('services.threads.webhook_secret', null);

        $this->postEvent($this->reply('reply-1'))->assertStatus(503);

        $this->assertSame(0, Interaction::acrossProjects()->count());
    }

    #[Test]
    public function a_correctly_signed_event_becomes_a_conversation_and_starts_a_draft(): void
    {
        Queue::fake();

        $this->postEvent($this->reply('reply-1'))->assertOk();

        $interaction = Interaction::acrossProjects()->sole();

        $this->assertSame('reply-1', $interaction->external_id);
        $this->assertSame($this->project->getKey(), $interaction->project_id);
        $this->assertSame($this->channel->getKey(), $interaction->channel_id);
        $this->assertSame('Does vinegar actually work on this?', $interaction->text);
        $this->assertSame('asker', $interaction->author);
        $this->assertSame('@asker', $interaction->author_handle);
        $this->assertSame('root-1', $interaction->root_external_id);
        $this->assertSame('parent-1', $interaction->parent_external_id);
        $this->assertTrue($interaction->state->value === 'new');

        // Dispatched from the receiver rather than left to `engine:tick`, whose
        // `isBusy()` would hold it behind any running pipeline. §4.2 measures
        // this contour in minutes.
        Queue::assertPushed(DraftInteractionReplyJob::class, 1);
    }

    #[Test]
    public function a_redelivered_event_does_not_become_a_second_conversation(): void
    {
        Queue::fake();

        // Meta's signature carries no timestamp, so nothing upstream can tell a
        // redelivery from the original — and Meta redelivers on purpose. The
        // guarantee is the unique index on (channel_id, external_id) and an
        // insert that yields to it, not a lock.
        $this->postEvent($this->reply('reply-1'))->assertOk();
        $this->postEvent($this->reply('reply-1'))->assertOk();

        $this->assertSame(1, Interaction::acrossProjects()->count());
        Queue::assertPushed(DraftInteractionReplyJob::class, 1);
    }

    #[Test]
    public function a_redelivery_does_not_overwrite_what_the_operator_has_done_since(): void
    {
        Queue::fake();

        $this->postEvent($this->reply('reply-1'))->assertOk();

        Interaction::acrossProjects()->sole()->markDrafted('Vinegar helps, but not on marble.');

        $this->postEvent($this->reply('reply-1'))->assertOk();

        $interaction = Interaction::acrossProjects()->sole();

        $this->assertSame('drafted', $interaction->state->value);
        $this->assertSame('Vinegar helps, but not on marble.', $interaction->draft_reply);
    }

    #[Test]
    public function an_event_for_a_threads_user_nobody_connected_is_answered_200_and_dropped(): void
    {
        Queue::fake();

        // 200 rather than 404 or 500 on purpose: anything else and Meta retries
        // an event that can never resolve, forever.
        $this->postEvent($this->reply('reply-1', userId: '17841499999999999'))->assertOk();

        $this->assertSame(0, Interaction::acrossProjects()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function one_delivery_carrying_several_replies_stores_all_of_them(): void
    {
        Queue::fake();

        $payload = [
            'object' => 'user',
            'entry' => [[
                'id' => self::USER_ID,
                'time' => 1_786_000_000,
                'changes' => [
                    ['field' => 'replies', 'value' => $this->replyValue('reply-1')],
                    ['field' => 'replies', 'value' => $this->replyValue('reply-2')],
                ],
            ]],
        ];

        $this->postEvent($payload)->assertOk();

        $this->assertSame(2, Interaction::acrossProjects()->count());
        Queue::assertPushed(DraftInteractionReplyJob::class, 2);
    }

    // -------------------------------------------------------------- RSS

    #[Test]
    public function a_broken_feed_does_not_stop_the_others_being_read(): void
    {
        Http::fake([
            'unreachable.test/*' => fn (): never => throw new ConnectionException('Could not resolve host.'),
            'down.test/*' => Http::response('Service unavailable', 503),
            'garbage.test/*' => Http::response('<html><body>not a feed at all</body></html>'),
            'good.test/*' => Http::response($this->rss(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $items = app(FeedReader::class)->readAll([
            'https://unreachable.test/feed.xml',
            'https://down.test/feed.xml',
            'https://garbage.test/feed.xml',
            'https://good.test/feed.xml',
        ]);

        // The least reliable publisher on the list does not decide whether the
        // others are read.
        $this->assertCount(2, $items);
        $this->assertSame('Water charges rise in Lisbon', $items[0]->title);
        $this->assertSame('https://good.test/water-charges', $items[0]->url);
        $this->assertSame('2026-08-08T09:30:00+00:00', $items[0]->publishedAt?->toIso8601String());
        $this->assertSame('https://good.test/feed.xml', $items[0]->feedUrl);
    }

    #[Test]
    public function a_feed_that_redirects_to_a_private_address_is_refused(): void
    {
        Http::fake([
            'feed.test/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/latest.xml']),
            'good.test/*' => Http::response($this->rss(), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $items = app(FeedReader::class)->readAll([
            'https://feed.test/rss',
            'https://good.test/feed.xml',
        ]);

        // The redirect is validated at the hop, not only at the address the
        // operator typed — which is the whole reason the fetch goes through
        // PublicHttpClient. And the run carries on.
        $this->assertCount(2, $items);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '127.0.0.1'));
    }

    #[Test]
    public function atom_and_rss_come_out_of_the_reader_in_the_same_shape(): void
    {
        Http::fake(['atom.test/*' => Http::response($this->atom(), 200, ['Content-Type' => 'application/atom+xml'])]);

        $items = app(FeedReader::class)->read('https://atom.test/feed');

        $this->assertCount(1, $items);
        $this->assertSame('tag:atom.test,2026:1', $items[0]->id);
        $this->assertSame('Council raises the water tariff', $items[0]->title);
        // The `alternate` link, not `self` — the first link in the document is
        // the API endpoint, which is not a story.
        $this->assertSame('https://atom.test/tariff', $items[0]->url);
        $this->assertSame('2026-08-07T11:00:00+00:00', $items[0]->publishedAt?->toIso8601String());
    }

    #[Test]
    public function a_feed_that_lies_about_its_encoding_is_still_read(): void
    {
        // Declares UTF-8, delivers Windows-1252. The single most common defect
        // in the wild, and for a project publishing in Portuguese it is most of
        // the words rather than a stray character.
        $body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss version=\"2.0\"><channel><title>Not\xE7\xEDcias</title>"
            ."<item><title>Pre\xE7o da \xE1gua sobe</title><link>https://liar.test/agua</link>"
            .'<guid>liar-1</guid></item></channel></rss>';

        Http::fake(['liar.test/*' => Http::response($body, 200, ['Content-Type' => 'text/xml'])]);

        $items = app(FeedReader::class)->read('https://liar.test/feed');

        $this->assertCount(1, $items);
        $this->assertSame('Preço da água sobe', $items[0]->title);
    }

    #[Test]
    public function a_feed_entry_with_no_usable_link_keeps_a_stable_identity(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Bare</title>'
            .'<item><title>Something happened</title><link>javascript:alert(1)</link></item>'
            .'</channel></rss>';

        Http::fake(['bare.test/*' => Http::response($body)]);

        $first = app(FeedReader::class)->read('https://bare.test/feed');
        $second = app(FeedReader::class)->read('https://bare.test/feed');

        $this->assertCount(1, $first);
        // Not a link anything could be persuaded to follow or render.
        $this->assertNull($first[0]->url);
        // And the same id on the next hourly run, so it deduplicates instead of
        // arriving as news every hour.
        $this->assertSame($first[0]->id, $second[0]->id);
    }

    // ------------------------------------------------- the whitelist column

    #[Test]
    public function the_feed_whitelist_round_trips_through_the_project_form(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'owner']);

        $this->actingAs($operator)->patch("/projects/{$this->project->getKey()}", [
            'name' => $this->project->name,
            'slug' => $this->project->slug,
            'timezone' => 'Europe/Lisbon',
            'default_locale' => 'en',
            'status' => 'active',
            'feed_urls' => ['  https://good.test/feed.xml  ', '', 'https://good.test/feed.xml', 'https://other.test/atom'],
        ])->assertRedirect('/projects');

        // Trimmed, blanks dropped, duplicates collapsed — a textarea produces
        // all three on the way in.
        $this->assertSame(
            ['https://good.test/feed.xml', 'https://other.test/atom'],
            $this->project->refresh()->feedUrls(),
        );
    }

    #[Test]
    public function a_feed_address_that_is_not_reachable_on_the_public_internet_is_refused(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'owner']);

        $this->actingAs($operator)->patch("/projects/{$this->project->getKey()}", [
            'name' => $this->project->name,
            'slug' => $this->project->slug,
            'timezone' => 'Europe/Lisbon',
            'default_locale' => 'en',
            'status' => 'active',
            'feed_urls' => ['http://192.168.1.10/feed.xml'],
        ])->assertSessionHasErrors('feed_urls.0');

        $this->assertSame([], $this->project->refresh()->feedUrls());
    }

    // ------------------------------------------------------------ helpers

    /**
     * The platform refuses `keyword_search` for want of a permission, and
     * answers `/{user-id}/threads` normally.
     */
    private function fakeRefusedScope(): void
    {
        Http::fake(function (Request $request): mixed {
            if (str_contains($request->url(), 'keyword_search')) {
                return Http::response([
                    'error' => ['message' => 'Application does not have permission for this action', 'code' => 10],
                ], 403);
            }

            return Http::response(['data' => $this->ownPosts()]);
        });
    }

    /**
     * The same refusal, worded rather than statused: a 400 that names the
     * permission it wants, which is the shape Meta uses at least as often.
     */
    private function fakeWordedRefusal(): void
    {
        Http::fake(function (Request $request): mixed {
            if (str_contains($request->url(), 'keyword_search')) {
                return Http::response(['error' => [
                    'message' => '(#200) This endpoint requires the following permission: '.ThreadsSearch::SCOPE.'.',
                    'code' => 200,
                ]], 400);
            }

            return Http::response(['data' => $this->ownPosts()]);
        });
    }

    private function fakeOwnPosts(): void
    {
        Http::fake(['graph.threads.net/*' => Http::response(['data' => $this->ownPosts()])]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownPosts(): array
    {
        return [
            $this->apiPost('own-1', 'Our note on limescale and hard water'),
            $this->apiPost('own-2', 'A post about something else entirely'),
        ];
    }

    /**
     * One post, shaped the way the API documents it.
     *
     * `username` is bare. Meta returns the handle without the `@` — the webhook
     * fake a few lines down has always said so — and a fake that adds one locks
     * an assertion onto a shape the platform does not send.
     *
     * @return array<string, mixed>
     */
    private function apiPost(string $id, string $text): array
    {
        return [
            'id' => $id,
            'text' => $text,
            'username' => 'asker',
            'permalink' => "https://www.threads.net/@asker/post/{$id}",
            'timestamp' => '2026-08-09T08:00:00+0000',
            'media_type' => 'TEXT',
        ];
    }

    /**
     * @return list<Request>
     */
    private function requestsTo(string $needle): array
    {
        /** @var list<Request> $requests */
        $requests = Http::recorded(static fn (Request $request): bool => str_contains(
            (string) parse_url($request->url(), PHP_URL_PATH),
            $needle,
        ))->map(static fn (array $pair): Request => $pair[0])->values()->all();

        return $requests;
    }

    /**
     * @return array<string, mixed>
     */
    private function reply(string $id, string $userId = self::USER_ID): array
    {
        return [
            'object' => 'user',
            'entry' => [[
                'id' => $userId,
                'time' => 1_786_000_000,
                'changes' => [['field' => 'replies', 'value' => $this->replyValue($id)]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replyValue(string $id): array
    {
        return [
            'id' => $id,
            'text' => 'Does vinegar actually work on this?',
            'username' => 'asker',
            'permalink' => "https://www.threads.net/@asker/post/{$id}",
            'timestamp' => '2026-08-09T08:15:00+0000',
            'replied_to' => ['id' => 'parent-1'],
            'root_post' => ['id' => 'root-1'],
        ];
    }

    /**
     * A delivery signed the way Meta signs one: HMAC-SHA256 over the raw body.
     *
     * `call()` rather than `postJson()`, so the bytes that are hashed are the
     * bytes that are sent. Anything that re-encodes the payload between the two
     * produces a different string and fails a signature that was correct.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function postEvent(array $payload, ?string $signature = null): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', self::WEBHOOK, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function rss(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">
              <channel>
                <title>Lisbon utilities</title>
                <item>
                  <title><![CDATA[Water charges rise in Lisbon]]></title>
                  <link>https://good.test/water-charges</link>
                  <guid isPermaLink="false">good-1</guid>
                  <description>&lt;p&gt;The council has approved a 12% rise.&lt;/p&gt;</description>
                  <pubDate>Sat, 08 Aug 2026 09:30:00 +0000</pubDate>
                </item>
                <item>
                  <title>Metered billing arrives in two more parishes</title>
                  <link>https://good.test/metered-billing</link>
                  <dc:date>2026-08-07T14:00:00Z</dc:date>
                </item>
              </channel>
            </rss>
            XML;
    }

    private function atom(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Council notices</title>
              <entry>
                <id>tag:atom.test,2026:1</id>
                <title>Council raises the water tariff</title>
                <link rel="self" type="application/atom+xml" href="https://atom.test/feed/1"/>
                <link rel="alternate" type="text/html" href="https://atom.test/tariff"/>
                <summary>Effective from September.</summary>
                <published>2026-08-07T11:00:00Z</published>
                <updated>2026-08-09T06:00:00Z</updated>
              </entry>
            </feed>
            XML;
    }
}
