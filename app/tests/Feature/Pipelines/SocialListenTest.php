<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\ContentItemState;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Enums\SignalKind;
use App\Enums\SignalSource;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\Signal;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\SocialListenPipeline;
use App\Pipelines\Steps\SocialListen\Deduplicate;
use App\Pipelines\Steps\SocialListen\FeedPlanner;
use App\Pipelines\Steps\SocialListen\FetchFeeds;
use App\Pipelines\Steps\SocialListen\FetchMentions;
use App\Pipelines\Steps\SocialListen\FetchSearch;
use App\Pipelines\Steps\SocialListen\Normalise;
use App\Pipelines\Steps\SocialListen\StoreSignals;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 12.3b — §4.1, the listening contour, end to end.
 *
 * The exit criterion of the phase is one sentence of §12: "Контур слушания даёт
 * планировщику статей вопросы из реальных разговоров, и хотя бы одна тема плана
 * пришла оттуда." Most of what is asserted below is either that sentence or the
 * dedup §4.1 puts next to it, because a contour that produces five drafts of
 * one news item is worse than one that produces none.
 *
 * Nothing reaches the network: `Http::preventStrayRequests()` is on globally and
 * every intake is faked.
 */
final class SocialListenTest extends TestCase
{
    use RefreshDatabase;

    /** The one term this project's corpus makes it listen for. */
    private const string TERM = 'limescale';

    private Project $project;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-09 12:00:00');

        $this->project = Project::factory()->create(['feed_urls' => []]);
        app(CurrentProject::class)->set($this->project);

        config()->set('services.threads.client_id', 'threads-app-id');
        config()->set('services.threads.client_secret', 'threads-app-secret');
        config()->set('queue.default', 'sync');

        Channel::factory()->threads()->create(['verified_at' => now()]);
        ProjectIntegration::factory()->threads()->create();

        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;

        $this->seedVocabulary();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ one subject

    #[Test]
    public function one_subject_from_search_and_from_a_feed_becomes_one_signal(): void
    {
        // The same story, two intakes, two ids: the platform's post id and the
        // feed's guid. §4.1 exists because keying the window on either of those
        // lets both through.
        $this->withFeeds(['https://good.test/feed.xml']);
        $this->fakeThreads([$this->apiPost('post-1', 'Limescale everywhere since the water charges went up')]);
        $this->fakeFeed('good.test', $this->rss('Water charges rise in Lisbon', 'guid-1'));
        $this->classifyAs('1 | OTHER | Water charges rise in Lisbon');

        $run = $this->listen();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(1, Signal::query()->count());

        $signal = Signal::query()->firstOrFail();

        $this->assertSame('Water charges rise in Lisbon', $signal->title);

        // Corroboration: one subject from two independent intakes is the
        // strongest evidence this contour produces, so the survivor is scored
        // up rather than merely kept.
        //
        // The exact number, because a range does not test this. The heavier of
        // the two is the post — a trend at 30, plus 5 for resolving `limescale`
        // and 20 for being an hour old, so 55 — and the feed item behind it
        // scores 44. `assertGreaterThan(50)` passed on the 55 alone and would
        // have gone on passing with the bonus deleted, which is the only thing
        // it was there to protect.
        $this->assertSame(65, $signal->weight);
    }

    #[Test]
    public function one_post_read_twice_in_the_same_hour_is_not_corroboration(): void
    {
        // §4.1 asks every term in both modes and a project's terms overlap, so
        // the commonest collision in the batch is one post meeting itself. The
        // bonus is for "one subject arriving from two independent sources"; a
        // post read twice is one source, and paying for it is +10 for reading.
        $this->fakeBothModes([$this->apiPost('post-1', 'has anyone got limescale off a kettle')]);

        // Both readings are classified, and the classification phrases the same
        // subject the same way — the case where nothing but the platform id
        // could tell the second arrival from a second post.
        $this->classifyAs(implode("\n", [
            '1 | QUESTION | Removing limescale from a kettle',
            '2 | QUESTION | Removing limescale from a kettle',
        ]));

        $this->listen();

        $this->assertSame(1, Signal::query()->count());

        // 45 for a question, 5 for `limescale`, 20 for an hour old. Not 80.
        $this->assertSame(70, Signal::query()->firstOrFail()->weight);
    }

    #[Test]
    public function one_post_reaching_two_intakes_is_one_signal(): void
    {
        // A reply under one of our posts arrives twice: `FetchMentions` reads
        // it from `/replies` as `threads_webhook`, and `keyword_search` finds
        // the same post as `threads_keyword_search`. Same platform id, two
        // sources — and `signals_external_id_unique` is keyed on the source, so
        // nothing in the database catches it either. Two signals from one post,
        // and potentially two article ideas.
        $reply = [
            'id' => 'reply-1',
            'text' => 'does vinegar do anything for limescale',
            'username' => 'asker',
            'permalink' => 'https://www.threads.net/@asker/post/reply-1',
            'timestamp' => '2026-08-09T11:00:00+0000',
            'replied_to' => ['id' => 'parent-1'],
            'root_post' => ['id' => 'root-1'],
        ];

        $this->fakeThreads([$this->apiPost('reply-1', $reply['text'])], [$reply]);

        // Two phrasings of one subject, which is what makes this about the
        // platform id: the subject keys differ, so nothing else collapses them.
        $this->classifyAs(implode("\n", [
            '1 | QUESTION | Does vinegar remove limescale',
            '2 | QUESTION | Vinegar against limescale',
        ]));

        $this->listen();

        $this->assertSame(1, Signal::query()->count());
        $this->assertSame(1, ContentItem::query()->whereNotNull('signal_id')->count());

        // And no bonus for it: it is one post, however many intakes read it.
        $this->assertSame(70, Signal::query()->firstOrFail()->weight);
    }

    // ---------------------------------------------- dedup: published, 30 days

    #[Test]
    public function a_subject_published_twenty_days_ago_is_deduplicated(): void
    {
        $this->published('Water charges rise in Lisbon', days: 20);

        $this->fakeThreads([]);
        $this->withFeeds(['https://good.test/feed.xml']);
        $this->fakeFeed('good.test', $this->rss('Water charges rise in Lisbon', 'guid-1'));

        $run = $this->listen();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(0, Signal::query()->count());

        $rejected = $this->payload($run, Deduplicate::key())['rejected'];

        $this->assertContains('this project published something about it within the last 30 days', $rejected);
    }

    #[Test]
    public function the_same_subject_published_forty_days_ago_is_not(): void
    {
        // Thirty days and not forever. A subject the site covered six weeks ago
        // is fair game again — the same judgement `SelectTopics` makes about
        // the corpus, and the reason §4.1 names a window rather than a rule.
        $this->published('Water charges rise in Lisbon', days: 40);

        $this->fakeThreads([]);
        $this->withFeeds(['https://good.test/feed.xml']);
        $this->fakeFeed('good.test', $this->rss('Water charges rise in Lisbon', 'guid-1'));

        $this->listen();

        $this->assertSame(1, Signal::query()->count());
        $this->assertSame('Water charges rise in Lisbon', Signal::query()->value('title'));
    }

    // ------------------------------------------------------- dedup: the queue

    #[Test]
    public function a_subject_already_sitting_in_the_queue_is_deduplicated(): void
    {
        // The half of §4.1 that produces the five near-identical drafts. Nothing
        // has been published — that is the point: the drafts are in front of an
        // operator, and a dedup that only looks at what is live has no idea.
        ContentItem::factory()->create([
            'title' => 'Water charges rise in Lisbon',
            'target_query' => 'Water charges rise in Lisbon',
            'entities' => [],
            'state' => ContentItemState::Draft,
        ]);

        $this->fakeThreads([]);
        $this->withFeeds(['https://good.test/feed.xml']);
        $this->fakeFeed('good.test', $this->rss('Water charges rise in Lisbon', 'guid-1'));

        $run = $this->listen();

        $this->assertSame(0, Signal::query()->count());

        $rejected = $this->payload($run, Deduplicate::key())['rejected'];

        $this->assertContains('this project already has something about it in the queue', $rejected);
    }

    #[Test]
    public function running_the_hour_twice_does_not_double_the_table(): void
    {
        $this->fakeThreads([$this->apiPost('post-1', 'Anything that actually shifts limescale?')]);
        $this->classifyAs('1 | QUESTION | Removing limescale from a kettle');

        $this->listen();
        $this->listen();

        // The second run's candidate is matched against the signal the first
        // one wrote, so the hourly schedule is idempotent without the schedule
        // knowing anything about it.
        $this->assertSame(1, Signal::query()->count());
        $this->assertSame(1, ContentItem::query()->whereNotNull('signal_id')->count());
    }

    // ---------------------------------------------------------- §11.2 answers

    #[Test]
    public function a_degraded_search_still_produces_signals_and_does_not_fail(): void
    {
        // §11.2: the `threads_keyword_search` scope was never approved, so the
        // adapter reads our own posts instead. Working on less, not broken.
        Http::fake(function (Request $request): mixed {
            if (str_contains($request->url(), 'keyword_search')) {
                return Http::response([
                    'error' => ['message' => 'Application does not have permission for this action', 'code' => 10],
                ], 403);
            }

            if (str_contains($request->url(), '/replies')) {
                return Http::response(['data' => []]);
            }

            return Http::response(['data' => [$this->apiPost('own-1', 'Our note on limescale and hard water')]]);
        });

        $run = $this->listen();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $signal = Signal::query()->firstOrFail();

        // Ours, and recorded as ours. §1 puts half the channel's value in
        // "чужие разговоры, а не свои посты", so a post of our own must not be
        // counted as a conversation — or weighted like one.
        $this->assertSame(SignalKind::Repurpose, $signal->kind);
        $this->assertSame(SignalSource::ThreadsKeywordSearch, $signal->source);

        // Exactly 30: a repurpose starts at 20, resolves `limescale` for 5 and
        // is an hour old for 20, and §11.2's penalty takes 15 off. The old
        // `assertLessThan(50)` held at 45 too — that is, with the penalty
        // deleted — so the one number it was written to protect was the one it
        // did not check.
        $this->assertSame(30, $signal->weight);

        // And it does not reach the article planner: §1.3 sends the planner
        // questions from other people, not questions we asked ourselves.
        $this->assertSame(0, ContentItem::query()->whereNotNull('signal_id')->count());

        // §7: the operator's summary is owed "чего движок делать не стал и
        // почему", and an hour spent listening to ourselves is exactly that.
        // The degradation logs one `notice` on the day it starts and nothing
        // afterwards, so the run is the only place it is still visible.
        $context = $run->refresh()->context;

        $this->assertTrue($context['social_listen.degraded']);
        $this->assertStringContainsString(
            'threads_keyword_search',
            (string) $context['social_listen.degraded_because'],
        );
    }

    #[Test]
    public function every_term_goes_out_in_both_modes_and_a_phrase_only_as_a_keyword(): void
    {
        // §4.1: "в режимах `RECENT` и `TAG`". The fake answers the tag pass with
        // nothing by design — which is the commoner outcome and keeps the
        // classification numbering readable — and the consequence was that no
        // test anywhere asserted the tag pass happened at all. Deleting it
        // would have broken half of §4.1 and nothing red.
        ContentItem::factory()->create([
            'title' => 'Softening hard water at home',
            'target_query' => 'hard water softener',
            'entities' => ['hard water'],
            'cluster' => 'hard water',
        ]);

        $this->fakeThreads([]);

        $this->listen();

        $this->assertNotEmpty($this->searches('search_mode=KEYWORD'));
        $this->assertNotEmpty($this->searches('search_mode=TAG'));

        // Both terms are asked as keywords…
        $this->assertCount(2, $this->searches('search_mode=KEYWORD'));

        // …and only the single-token one as a tag. A tag is one token, so
        // `hard water` in the tag pass is a request the platform cannot honour:
        // at best it answers for `hard`, at worst it refuses, and either way it
        // is half the tag budget of §2 spent on a question we did not ask.
        $this->assertCount(1, $this->searches('search_mode=TAG'));
        $this->assertEmpty($this->searches('search_mode=TAG', 'hard'));
    }

    #[Test]
    public function an_empty_answer_for_a_sensitive_term_produces_nothing_and_does_not_fail(): void
    {
        // §11.2: "по «чувствительным» словам API молча отдаёт пустой массив —
        // это не ошибка транспорта". Not a fault, not a retry, not a signal.
        Http::fake(['graph.threads.net/*' => Http::response(['data' => []])]);

        $run = $this->listen();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(0, Signal::query()->count());

        $this->assertSame(
            PipelineStepStatus::Succeeded,
            $this->step($run, FetchSearch::key())->status,
        );
    }

    #[Test]
    public function a_full_search_window_stops_the_loop_and_says_so_on_the_run(): void
    {
        // §2's 2 200/24 h is spent. §5 is unambiguous that a ceiling is a
        // ceiling and undershooting is allowed, so this is a successful hour
        // that did less — and §7 requires the run to say what it did not do and
        // why, which is the half that was computed and told to nobody.
        config()->set('social.threads.search_requests_per_day', 0);

        $this->fakeThreads([$this->apiPost('post-1', 'anything that shifts limescale?')]);

        $run = $this->listen();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(
            PipelineStepStatus::Succeeded,
            $this->step($run, FetchSearch::key())->status,
        );

        // Nothing went out: the ceiling is checked before the request, so a
        // full window costs not even the round trip.
        $this->assertEmpty($this->searches('keyword_search'));
        $this->assertSame(0, Signal::query()->count());

        $this->assertTrue($this->payload($run, FetchSearch::key())['budget_spent']);

        // And on the run, where 12.6's daily summary can read it. A payload is
        // read by the next step and by nothing else.
        $context = $run->context;

        $this->assertTrue($context['social_listen.budget_spent']);
        $this->assertStringContainsString('2 200', (string) $context['social_listen.stopped_because']);
    }

    // ------------------------------------------------------------------- RSS

    #[Test]
    public function one_unreachable_feed_does_not_stop_the_others(): void
    {
        $this->withFeeds(['https://unreachable.test/feed.xml', 'https://good.test/feed.xml']);

        Http::fake([
            'unreachable.test/*' => fn (): never => throw new ConnectionException('Could not resolve host.'),
            'good.test/*' => Http::response($this->rss('Water charges rise in Lisbon', 'guid-1'), 200, [
                'Content-Type' => 'application/rss+xml',
            ]),
            'graph.threads.net/*' => Http::response(['data' => []]),
        ]);

        $run = $this->listen();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(1, Signal::query()->count());
        $this->assertSame(SignalSource::Rss, Signal::query()->firstOrFail()->source);

        // The address is recorded rather than swallowed: the reader never
        // throws, so this list is the only place an operator learns that a feed
        // they added has stopped answering.
        $this->assertSame(
            ['https://unreachable.test/feed.xml'],
            $this->payload($run, FetchFeeds::key())['silent'],
        );
    }

    #[Test]
    public function a_news_signal_carries_the_window_it_dies_in(): void
    {
        $this->withFeeds(['https://good.test/feed.xml']);
        $this->fakeThreads([]);
        $this->fakeFeed('good.test', $this->rss('Water charges rise in Lisbon', 'guid-1'));

        $this->listen();

        $signal = Signal::query()->firstOrFail();

        // §5: the reactive band has a TTL, and a draft that misses it is killed
        // rather than published late.
        $this->assertSame(SignalKind::News, $signal->kind);
        $this->assertNotNull($signal->expires_at);
        $this->assertTrue($signal->expires_at->isFuture());
    }

    // --------------------------------------------------- §1.3, the exit criterion

    #[Test]
    public function a_question_from_a_real_conversation_reaches_the_planner_as_an_idea(): void
    {
        $this->fakeThreads([
            $this->apiPost('post-1', 'has anyone got limescale off a kettle without wrecking it'),
        ]);

        // The model's job, and the one place in this contour it earns a call:
        // that post is a question and it has no question mark in it.
        $this->classifyAs('1 | QUESTION | Removing limescale from a kettle');

        $run = $this->listen();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $signal = Signal::query()->firstOrFail();
        $this->assertSame(SignalKind::Question, $signal->kind);

        $idea = ContentItem::query()->whereNotNull('signal_id')->firstOrFail();

        // §1.3: "Статья ранжируется и цитируется, Threads находит, о чём её
        // писать." The reverse flow, and the phase's exit criterion.
        $this->assertSame(ContentItemState::Idea, $idea->state);
        $this->assertSame('Removing limescale from a kettle', $idea->title);
        $this->assertSame('Removing limescale from a kettle', $idea->target_query);
        $this->assertNull($idea->content_plan_id);

        // §3's whole reason for the table: a month from now, "does the
        // listening contour pay for itself" is a group-by rather than an
        // argument.
        $this->assertSame($signal->getKey(), $idea->signal_id);
        $this->assertSame($idea->getKey(), $signal->contentItems()->firstOrFail()->getKey());
    }

    #[Test]
    public function a_listened_question_outranks_the_keyword_pool_when_the_month_is_planned(): void
    {
        // The exit criterion of §12 in the only form that means anything:
        // "хотя бы одна тема плана пришла оттуда". Reaching the idea pool is
        // not the claim — being *chosen* out of it is, and a pool is a ranking.
        //
        // This is the test that was missing, and its absence is why the bug it
        // now catches shipped. `GatherIdeas` ranks by volume over difficulty; a
        // listened question has neither, because nobody typed it into a search
        // box. Scored on that scale it is a flat zero and sorts behind every
        // keyword idea in the project, so in any project whose research
        // pipeline has ever run it can never be selected. The old test asserted
        // `content_plan_id` was null — a column default — and never ran the
        // planner at all.
        ContentItem::factory()->count(3)->create([
            'state' => ContentItemState::Idea,
            'content_plan_id' => null,
            'topic_volume' => 5_000,
            'topic_difficulty' => 5,
        ]);

        $this->fakeThreads([
            $this->apiPost('post-1', 'has anyone got limescale off a kettle without wrecking it'),
        ]);
        $this->classifyAs('1 | QUESTION | Removing limescale from a kettle');
        $this->listen();

        $listened = ContentItem::query()->whereNotNull('signal_id')->firstOrFail();

        $pool = app(PipelineRunner::class);
        $run = $pool->start('planning', $this->project, ['month' => now()->addMonth()->startOfMonth()->toDateString()]);

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        // §6: a subject people are discussing that the site has no page for
        // goes to the planner *с приоритетом*. Three keyword ideas at 5000
        // searches and difficulty 5 would each score 333 against the listened
        // question's zero, so without the priority tier this is last of four.
        $this->assertNotNull(
            $listened->refresh()->content_plan_id,
            'A question from a real conversation was gathered into the pool and then never chosen out of it.',
        );
    }

    #[Test]
    public function a_stale_question_about_nothing_the_project_covers_is_under_the_bar(): void
    {
        // The only way under `FeedPlanner::MIN_WEIGHT`, and it is a
        // conjunction: 45 for a question, nothing for entities because the
        // subject resolves into none of the project's, and nothing for
        // freshness because it is four days old. 45, and the pool declines it.
        //
        // Every other question in this suite scores 70, so the bar itself was
        // never exercised: it could have been 0 and nothing would have failed.
        $this->fakeThreads([
            $this->apiPost('post-1', 'is a softener worth it for the whole house', at: '2026-08-05T09:00:00+0000'),
        ]);
        $this->classifyAs('1 | QUESTION | Whether a whole-house softener is worth it');

        $run = $this->listen();

        // Still recorded — somebody asked, and §3 wants to know that.
        $signal = Signal::query()->firstOrFail();

        $this->assertSame(SignalKind::Question, $signal->kind);
        $this->assertSame(45, $signal->weight);

        // But not an article.
        $this->assertSame(0, ContentItem::query()->whereNotNull('signal_id')->count());
        $this->assertContains(
            'not weighty enough to be worth an article',
            $this->payload($run, FeedPlanner::key())['skipped'],
        );
    }

    #[Test]
    public function a_fresh_question_the_project_has_no_entity_for_is_deliberately_still_planned(): void
    {
        // The same unresolved subject, asked an hour ago: 45 + 0 + 20 = 65, and
        // it becomes an article idea. Deliberate, and the docblock on
        // `MIN_WEIGHT` now says so rather than implying an entity gate that
        // does not exist. §4.3's hard gate is on a native *post*; the reverse
        // flow of §1.3 is a question going to the article planner, and §6 makes
        // "there is a conversation and the site has no page" the reason to
        // write rather than the reason to refuse — a reply under one of our own
        // posts ("does vinegar actually work on this?") resolves into nothing
        // at all and is the best idea of the hour.
        $this->fakeThreads([
            $this->apiPost('post-1', 'is a softener worth it for the whole house'),
        ]);
        $this->classifyAs('1 | QUESTION | Whether a whole-house softener is worth it');

        $this->listen();

        $signal = Signal::query()->firstOrFail();

        $this->assertSame([], $signal->entities);
        $this->assertSame(65, $signal->weight);
        $this->assertSame(1, ContentItem::query()->whereNotNull('signal_id')->count());
    }

    #[Test]
    public function one_hour_may_only_add_so_many_questions_to_the_pool(): void
    {
        // §5's ceiling, applied to the reverse flow: a hot day produces forty
        // questions about one launch, the dedup collapses the near-identical
        // ones, and this catches the day the dedup is right and there really
        // are forty. The rest are not lost — the pool is the planner's input,
        // so next hour picks them up.
        $posts = [];
        $lines = [];

        foreach (range(1, 7) as $n) {
            $posts[] = $this->apiPost("post-{$n}", "question number {$n} about limescale");
            $lines[] = "{$n} | QUESTION | Limescale question number {$n}";
        }

        $this->fakeThreads($posts);
        $this->classifyAs(implode("\n", $lines));

        $run = $this->listen();

        $this->assertSame(7, Signal::query()->count());
        $this->assertSame(5, ContentItem::query()->whereNotNull('signal_id')->count());

        $skipped = $this->payload($run, FeedPlanner::key())['skipped'];

        $this->assertCount(2, $skipped);
        $this->assertContains('over what one listening run may add to the pool', $skipped);
    }

    #[Test]
    public function an_article_the_site_already_covers_does_not_become_an_idea(): void
    {
        // Published long enough ago that the thirty-day window of §4.1 lets the
        // signal itself through — so what is being tested here is the planner's
        // own corpus check and not the dedup pass in front of it.
        $this->published('Removing limescale from a kettle', days: 120);

        $this->fakeThreads([
            $this->apiPost('post-1', 'has anyone got limescale off a kettle without wrecking it'),
        ]);
        $this->classifyAs('1 | QUESTION | Removing limescale from a kettle');

        $run = $this->listen();

        // The conversation is still worth recording — somebody asked, and §3
        // wants to know that.
        $this->assertSame(1, Signal::query()->count());

        // It is not worth an article the site already has.
        $this->assertSame(0, ContentItem::query()->whereNotNull('signal_id')->count());

        $this->assertContains(
            'the site already covers this',
            $this->payload($run, FeedPlanner::key())['skipped'],
        );
    }

    // ------------------------------------------------------- the normal case

    #[Test]
    public function a_project_with_no_integration_and_no_feeds_skips_rather_than_failing(): void
    {
        // Three of the four projects this engine runs. Silence, not an error:
        // an hourly failure for every unconnected project teaches an operator
        // to stop reading failures, which is how a real one gets missed.
        ProjectIntegration::query()->delete();
        Channel::query()->delete();

        $run = $this->listen();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        foreach ($run->steps()->get() as $step) {
            // Skipped releases dependants exactly as success does, so the funnel
            // behind the fan-out settles rather than hanging: a skip must mean
            // "unnecessary", and for a project nobody connected it does.
            $this->assertSame(PipelineStepStatus::Skipped, $step->status, $step->step_key);
        }

        $this->assertSame(0, Signal::query()->count());
        $this->assertSame(0, ContentItem::query()->whereNotNull('signal_id')->count());
    }

    #[Test]
    public function the_command_does_not_even_start_a_run_for_a_project_with_nothing_to_listen_to(): void
    {
        ProjectIntegration::query()->delete();

        $this->console('social:listen');

        // Seven step rows an hour, twenty-four hours a day, per project, to
        // record that there was nothing to do.
        $this->assertSame(0, PipelineRun::query()->count());
    }

    #[Test]
    public function the_command_starts_one_run_for_a_connected_project(): void
    {
        Http::fake(['graph.threads.net/*' => Http::response(['data' => []])]);

        $this->console('social:listen', ['project' => $this->project->slug]);

        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'social_listen')->count());
    }

    // -------------------------------------------------------------- §11.3

    #[Test]
    public function listening_is_on_the_schedule_hourly_and_on_its_own(): void
    {
        /** @var list<Event> $events */
        $events = app(Schedule::class)->events();

        $expressions = [];

        foreach ($events as $event) {
            foreach (['social:listen', 'signals:reap'] as $command) {
                if (str_contains((string) $event->command, $command)) {
                    $expressions[$command] = $event->expression;
                }
            }
        }

        // §11.3: "Планировщик движка пуст… Статья это терпит, соцкалендарь —
        // нет." Its own entry, because `engine:tick` refuses to start anything
        // while a pipeline is running and listening cannot wait behind a
        // generation run.
        $this->assertSame('0 * * * *', $expressions['social:listen'] ?? null);
        $this->assertArrayHasKey('signals:reap', $expressions);
    }

    // ------------------------------------------------------------ the reaper

    #[Test]
    public function the_reaper_takes_dead_signals_and_spares_everything_else(): void
    {
        $dead = Signal::factory()->expired()->create();
        $consumed = Signal::factory()->expired()->consumed()->create();
        $noWindow = Signal::factory()->create(['expires_at' => null]);

        $attributed = Signal::factory()->expired()->create();
        ContentItem::factory()->create(['signal_id' => $attributed->getKey()]);

        $this->console('signals:reap');

        $this->assertNull(Signal::query()->find($dead->getKey()));

        // Each of the three below is a row a naive reaper destroys, and the
        // last one is the expensive one: `signal_id` is `nullOnDelete`, so
        // deleting it would quietly blank the column and §3's per-source
        // attribution would lose the news contour first.
        $this->assertNotNull(Signal::query()->find($consumed->getKey()));
        $this->assertNotNull(Signal::query()->find($noWindow->getKey()));
        $this->assertNotNull(Signal::query()->find($attributed->getKey()));
    }

    // ------------------------------------------------------------- metering

    #[Test]
    public function the_run_is_metered_like_any_other(): void
    {
        $this->fakeThreads([$this->apiPost('post-1', 'anything that shifts limescale')]);
        $this->classifyAs('1 | QUESTION | Removing limescale from a kettle');

        $run = $this->listen()->refresh();

        // Every step the pipeline declares, metered — as a count taken from the
        // definition rather than a number written here, for the reason
        // `ResearchPipelineTest` gives: what a literal protects is not the
        // number, it is "nothing runs unmetered".
        $this->assertSame(
            count((new SocialListenPipeline)->steps()),
            $run->steps()->count(),
        );

        $this->assertSame((int) $run->steps()->sum('cost_micros'), $run->cost_micros);

        // Listening is cheap but not free: one classification call an hour is
        // the line §8 asks to be reported separately.
        $this->assertGreaterThan(0, $run->cost_micros);
        $this->assertGreaterThan(0, $this->step($run, Normalise::key())->cost_micros);
        $this->assertSame(0, $this->step($run, Deduplicate::key())->cost_micros);
        $this->assertSame(0, $this->step($run, StoreSignals::key())->cost_micros);

        foreach ($run->steps()->get() as $step) {
            $this->assertNotNull($step->latency_ms);
        }
    }

    #[Test]
    public function the_reconciliation_pass_does_not_duplicate_what_the_webhook_wrote(): void
    {
        $this->fakeThreads([], [[
            'id' => 'reply-1',
            'text' => 'does vinegar work on limescale',
            'username' => 'asker',
            'permalink' => 'https://www.threads.net/@asker/post/reply-1',
            'timestamp' => '2026-08-09T11:00:00+0000',
            'replied_to' => ['id' => 'parent-1'],
            'root_post' => ['id' => 'root-1'],
        ]]);
        $this->classifyAs('1 | QUESTION | Does vinegar remove limescale');

        $first = $this->listen();
        $second = $this->listen();

        // `unique (channel_id, external_id)` decides it, through
        // `insertOrIgnore` — not a read-then-write that races the webhook.
        $this->assertSame(1, Interaction::query()->count());
        $this->assertSame(1, $this->payload($first, FetchMentions::key())['recovered']);
        $this->assertSame(0, $this->payload($second, FetchMentions::key())['recovered']);
    }

    // ----------------------------------------------------------------- setup

    /**
     * `artisan()` is declared as returning `PendingCommand|int`, and
     * assertSuccessful() only records the expectation — the command runs in
     * __destruct(), so it has to be run explicitly.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function console(string $command, array $arguments = []): void
    {
        /** @var PendingCommand $pending */
        $pending = $this->artisan($command, $arguments);

        $pending->assertSuccessful()->run();
    }

    private function listen(): PipelineRun
    {
        return app(PipelineRunner::class)->start('social_listen', $this->project);
    }

    /**
     * One unit, so the project has a vocabulary to listen with.
     *
     * `ProjectVocabulary` derives the search terms from the corpus rather than
     * from a configured list, so a project with no work behind it listens for
     * nothing — which is correct, and which every test here has to get past.
     */
    private function seedVocabulary(): void
    {
        ContentItem::factory()->create([
            'title' => 'How to descale a kettle',
            'target_query' => 'how to descale a kettle',
            'entities' => [self::TERM],
            'cluster' => self::TERM,
        ]);
    }

    /** @param  list<string>  $urls */
    private function withFeeds(array $urls): void
    {
        $this->project->forceFill(['feed_urls' => $urls])->save();
        $this->project->refresh();
    }

    private function published(string $subject, int $days): void
    {
        ContentItem::factory()->create([
            'title' => $subject,
            'target_query' => $subject,
            'entities' => [],
            'state' => ContentItemState::Published,
            'published_at' => Carbon::now()->subDays($days),
        ]);
    }

    /**
     * The Threads API: the keyword pass answers, the tag pass does not.
     *
     * §4.1 asks each term in both modes and the step does, so a fake that
     * answered both would hand every test two copies of one post. That is a
     * duplicate the dedup pass collapses correctly — and it would make the
     * classification numbering in each test a puzzle rather than an assertion.
     * A tag search finding nothing where a keyword search finds something is
     * also the commoner outcome in practice.
     *
     * @param  list<array<string, mixed>>  $posts
     * @param  list<array<string, mixed>>  $replies
     */
    private function fakeThreads(array $posts, array $replies = []): void
    {
        Http::fake([
            'graph.threads.net/*' => function (Request $request) use ($posts, $replies): mixed {
                $url = $request->url();

                if (str_contains($url, '/replies')) {
                    return Http::response(['data' => $replies]);
                }

                if (! str_contains($url, 'keyword_search') || str_contains($url, 'search_mode=TAG')) {
                    return Http::response(['data' => []]);
                }

                return Http::response(['data' => $posts]);
            },
        ]);
    }

    /**
     * The Threads API answering *both* passes with the same posts.
     *
     * What the platform does when a term is both a word people write and a tag
     * they use — and the case where one post arrives twice in one hour under
     * one id.
     *
     * @param  list<array<string, mixed>>  $posts
     */
    private function fakeBothModes(array $posts): void
    {
        Http::fake([
            'graph.threads.net/*' => function (Request $request) use ($posts): mixed {
                return str_contains($request->url(), 'keyword_search')
                    ? Http::response(['data' => $posts])
                    : Http::response(['data' => []]);
            },
        ]);
    }

    /**
     * The search requests that went out, matching every fragment given.
     *
     * @return list<Request>
     */
    private function searches(string ...$fragments): array
    {
        /** @var list<Request> $requests */
        $requests = Http::recorded(static function (Request $request) use ($fragments): bool {
            foreach ([...$fragments, 'keyword_search'] as $fragment) {
                if (! str_contains($request->url(), $fragment)) {
                    return false;
                }
            }

            return true;
        })->map(static fn (array $pair): Request => $pair[0])->values()->all();

        return $requests;
    }

    private function fakeFeed(string $host, string $body): void
    {
        Http::fake([
            "{$host}/*" => Http::response($body, 200, ['Content-Type' => 'application/rss+xml']),
        ]);
    }

    /** What the classification call answers, verbatim. */
    private function classifyAs(string $answer): void
    {
        $this->models->willAnswerRole('utility', $answer);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PipelineRun $run, string $stepKey): array
    {
        $output = $this->step($run, $stepKey)->output;

        return is_array($output) ? $output : [];
    }

    private function step(PipelineRun $run, string $stepKey): PipelineStep
    {
        return $run->steps()->where('step_key', $stepKey)->firstOrFail();
    }

    private function rss(string $title, string $guid): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0">
              <channel>
                <title>Lisbon utilities</title>
                <item>
                  <title><![CDATA[{$title}]]></title>
                  <link>https://good.test/water-charges</link>
                  <guid isPermaLink="false">{$guid}</guid>
                  <description>&lt;p&gt;The council has approved a 12% rise.&lt;/p&gt;</description>
                  <pubDate>Sun, 09 Aug 2026 09:30:00 +0000</pubDate>
                </item>
              </channel>
            </rss>
            XML;
    }

    /**
     * One post, as the API hands it over.
     *
     * `username` is bare, which is how Meta returns it — the same shape the
     * webhook fake in `ThreadsListeningTest` has always used.
     *
     * @return array<string, mixed>
     */
    private function apiPost(string $id, string $text, string $at = '2026-08-09T11:00:00+0000'): array
    {
        return [
            'id' => $id,
            'text' => $text,
            'username' => 'asker',
            'permalink' => "https://www.threads.net/@asker/post/{$id}",
            'timestamp' => $at,
            'media_type' => 'TEXT',
        ];
    }
}
