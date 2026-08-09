<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\InteractionState;
use App\Enums\SignalKind;
use App\Enums\SignalSource;
use App\Enums\SocialBand;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\ProjectState;
use App\Models\Signal;
use App\Support\Content\ConcurrentStateChange;
use App\Support\Content\InvalidStateTransition;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Normalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §3 of the social spec: the delta to the domain, and the rules that hold it up.
 *
 * The delta is deliberately small — a post stays a `ContentItem` — so most of
 * what is worth asserting here is what the three new tables refuse. A dedup
 * window, a duty queue and a daily snapshot are each defined by the row they
 * will not accept twice, and a rule enforced only by the one method that
 * currently writes it lasts until the second writer.
 */
final class SocialDomainTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
    }

    // ------------------------------------------------------------ the unit

    #[Test]
    public function a_social_unit_lives_its_whole_life_without_a_parent(): void
    {
        $post = ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'parent_id' => null,
            'social_band' => SocialBand::Question,
        ]);

        $post->markQueued()->markGenerating()->markDrafted()->approve()->markPublished();

        // §3: "родитель необязателен". A native post answering a question
        // nobody wrote an article about is the normal case for five of the six
        // bands, and the machine has to take it all the way to published.
        $this->assertSame(ContentItemState::Published, $post->refresh()->state);
        $this->assertNotNull($post->published_at);

        $this->assertTrue($post->isSocial());
        $this->assertFalse($post->isDerivative());
        $this->assertNull($post->parent_id);

        $this->assertSame(SocialBand::Question, $post->social_band);
    }

    #[Test]
    public function a_post_is_not_an_article_to_the_geo_layer(): void
    {
        // The whole reason the type exists rather than being reused: an
        // Explainer would have handed a 300-character post the schema.org type
        // of an article.
        $this->assertSame('SocialMediaPosting', ContentItemType::SocialPost->schemaType());
        $this->assertSame('Article', ContentItemType::Explainer->schemaType());
    }

    #[Test]
    public function threads_is_a_social_channel(): void
    {
        $this->assertTrue(ChannelType::Threads->isSocial());
    }

    #[Test]
    public function a_units_ttl_travels_with_the_draft(): void
    {
        $post = ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'social_band' => SocialBand::Reaction,
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        // §5: a reactive draft that misses its window is killed, not published
        // late. That is only automatable if the deadline is on the draft.
        $this->assertTrue($post->hasExpired());
        $this->assertFalse($post->hasExpired(Carbon::now()->subHour()));

        $this->assertFalse(ContentItem::factory()->create(['expires_at' => null])->hasExpired());
    }

    // ----------------------------------------------------------- signals

    #[Test]
    public function one_source_cannot_hand_over_the_same_id_twice(): void
    {
        Signal::factory()->create([
            'source' => SignalSource::Rss,
            'external_id' => 'https://feed.test/items/1',
        ]);

        $this->expectException(QueryException::class);

        // The hourly listener re-reads the same feed. Without the index it
        // accumulates a copy an hour.
        Signal::factory()->create([
            'source' => SignalSource::Rss,
            'external_id' => 'https://feed.test/items/1',
        ]);
    }

    #[Test]
    public function the_sources_that_have_no_ids_are_not_limited_to_one_row(): void
    {
        $first = Signal::factory()->create([
            'source' => SignalSource::Corpus,
            'external_id' => null,
        ]);
        $second = Signal::factory()->create([
            'source' => SignalSource::Corpus,
            'external_id' => null,
        ]);

        // A plain unique index would let exactly one corpus gap and one
        // seasonal curve exist per project and silently drop the rest, which is
        // why the index is partial.
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, Signal::query()->count());
    }

    #[Test]
    public function the_same_source_id_is_free_in_another_project(): void
    {
        Signal::factory()->create([
            'source' => SignalSource::Rss,
            'external_id' => 'https://feed.test/items/1',
        ]);

        $other = Project::factory()->create();

        // Both legs of the index matter and only one of them was tested. Two
        // projects subscribing to the same public feed is the normal case — a
        // trade RSS everyone in the sector reads — and an index that forgot
        // project_id would give the second project's listener nothing.
        $theirs = app(CurrentProject::class)->run($other, fn (): Signal => Signal::factory()->create([
            'source' => SignalSource::Rss,
            'external_id' => 'https://feed.test/items/1',
        ]));

        $this->assertSame('https://feed.test/items/1', $theirs->external_id);
    }

    #[Test]
    public function the_same_id_is_free_under_another_source(): void
    {
        Signal::factory()->create([
            'source' => SignalSource::Rss,
            'external_id' => '17812345',
        ]);

        $other = Signal::factory()->create([
            'source' => SignalSource::ThreadsKeywordSearch,
            'external_id' => '17812345',
        ]);

        // Two platforms numbering their own rows are not a collision.
        $this->assertSame('17812345', $other->external_id);
    }

    #[Test]
    public function a_fingerprint_is_the_subject_and_not_the_typing(): void
    {
        $canonical = Signal::fingerprintFor(
            'Lisbon water bill up 12 percent',
            ['Lisbon', 'water bill'],
        );

        // The same subject, re-typed by a different intake.
        $this->assertSame($canonical, Signal::fingerprintFor(
            '  LISBON  water bill, up 12 percent!  ',
            ['Lisbon', 'water bill'],
        ));

        // The same subject, resolved in the other order. Whichever resolver
        // happened to run first must not decide whether the second signal
        // counts as new.
        $this->assertSame($canonical, Signal::fingerprintFor(
            'Lisbon water bill up 12 percent',
            ['water bill', 'Lisbon'],
        ));

        // …and a genuinely different subject is a different fingerprint, or the
        // dedup of §4.1 would swallow the news it exists to space out.
        $this->assertNotSame($canonical, Signal::fingerprintFor(
            'Porto water bill up 12 percent',
            ['Porto', 'water bill'],
        ));
        $this->assertNotSame($canonical, Signal::fingerprintFor(
            'Lisbon water bill up 12 percent',
            ['Lisbon'],
        ));
    }

    #[Test]
    public function a_fingerprint_is_short_and_stable_in_shape(): void
    {
        $fingerprint = Signal::fingerprintFor('Anything at all', []);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fingerprint);

        // Pinned, not merely shaped: /^[0-9a-f]{32}$/ is also satisfied by a
        // full md5, so the regex above would survive the algorithm changing
        // underneath it. Fingerprints are persisted and compared against 30
        // days of history (§4.1), so a quiet change orphans every stored row —
        // the whole window reads as new and the engine re-drafts a month of
        // subjects it already covered. If this assertion fails, the change was
        // either deliberate and needs a backfill, or it was not deliberate.
        $this->assertSame(
            'eeae860d7ba6b0fa0cbb3fc53af72bba',
            Signal::fingerprintFor('Lisbon water bill up 12 percent', ['Lisbon', 'water bill']),
        );
    }

    #[Test]
    public function a_fingerprint_survives_entities_that_look_like_numbers(): void
    {
        // The comparator PHP's default sort uses is not transitive across a
        // mixture like this: "6" < "115" numerically, "115" < "24f" lexically
        // and "24f" < "6" lexically, so the sorted result depended on the order
        // the array arrived in and the fingerprint with it. Model numbers,
        // years, postcodes and plan tiers all produce exactly this mixture.
        $this->assertSame(
            Signal::fingerprintFor('Tariff change', ['115', '24f', '6', '138']),
            Signal::fingerprintFor('Tariff change', ['24f', '6', '115', '138']),
        );
    }

    #[Test]
    public function the_same_headline_typed_on_two_platforms_is_one_subject(): void
    {
        $composed = 'Água em Lisboa';
        $decomposed = (string) Normalizer::normalize($composed, Normalizer::FORM_D);

        // Two byte strings, one word. Apple clients and several RSS pipelines
        // emit NFD while the API returns whatever the poster typed, and the
        // decomposed acute is a \p{M} codepoint the normaliser's own
        // [^\p{L}\p{N}]+ replace turns into a space — "a gua" against "água".
        $this->assertNotSame($composed, $decomposed);

        $this->assertSame(
            Signal::fingerprintFor($composed, ['Água']),
            Signal::fingerprintFor(
                $decomposed,
                [(string) Normalizer::normalize('Água', Normalizer::FORM_D)],
            ),
        );
    }

    #[Test]
    public function an_entity_named_zero_is_still_an_entity(): void
    {
        // array_filter() with no callback tests truthiness, so "0" went out
        // with the empty strings. Two different subjects then shared a
        // fingerprint and the second was deduped away and never drafted.
        $this->assertNotSame(
            Signal::fingerprintFor('Plan tiers compared', ['0', 'a']),
            Signal::fingerprintFor('Plan tiers compared', ['a']),
        );
    }

    #[Test]
    public function a_signal_with_no_subject_at_all_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Guarded rather than documented. sha256('') is a perfectly good hash
        // and that is the problem: every untitled signal in a project would
        // share it, so §4.1 would suppress all of them but the first and the
        // intake would look like it was working.
        Signal::fingerprintFor('   ', []);
    }

    #[Test]
    public function the_live_scope_skips_the_expired_and_the_used(): void
    {
        $live = Signal::factory()->create(['expires_at' => Carbon::now()->addDay()]);
        $noWindow = Signal::factory()->create(['expires_at' => null]);
        Signal::factory()->expired()->create();
        Signal::factory()->consumed()->create();

        $ids = Signal::query()->live()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$live->getKey(), $noWindow->getKey()], $ids);

        // The two conditions live together because a query that filters on one
        // and forgets the other either re-drafts something already published or
        // comments on the day before yesterday.
        $this->assertTrue(Signal::factory()->expired()->create()->isExpired());
        $this->assertFalse($noWindow->isExpired());
    }

    #[Test]
    public function the_ttl_boundary_is_read_the_same_way_by_both_readers(): void
    {
        Carbon::setTestNow('2026-08-08 12:00:00');

        $signal = Signal::factory()->create(['expires_at' => Carbon::now()]);

        // `isExpired()` compares with `<=` and `scopeLive()` with `>`. They are
        // two spellings of one rule and they meet at exactly this instant, so
        // the only way to know they agree is to stand on it. If they ever
        // disagree, a draft is both killed for missing its window and still
        // offered to the planner as live.
        $this->assertTrue($signal->isExpired());
        $this->assertSame(0, Signal::query()->live()->count());

        Carbon::setTestNow('2026-08-08 11:59:59');

        $this->assertFalse($signal->isExpired());
        $this->assertSame(1, Signal::query()->live()->count());
    }

    #[Test]
    public function the_reaper_spares_everything_the_loop_learns_from(): void
    {
        $dead = Signal::factory()->expired()->create();

        $consumed = Signal::factory()->expired()->consumed()->create();

        $cited = Signal::factory()->expired()->create();
        ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'social_band' => SocialBand::Reaction,
            'signal_id' => $cited->getKey(),
        ]);

        $noWindow = Signal::factory()->create(['expires_at' => null]);
        $stillLive = Signal::factory()->create(['expires_at' => Carbon::now()->addDay()]);

        // Only the reactive band sets expires_at (§5), so a reaper keyed on age
        // rather than on the window would erase every corpus gap and seasonal
        // curve in the table. And `content_items.signal_id` is nullOnDelete, so
        // deleting a cited signal does not fail — it silently blanks the
        // column, and the per-source attribution of §3 loses the news contour
        // first, which is the exact question the spec says this table answers.
        $this->assertSame([$dead->getKey()], Signal::query()->reapable()->pluck('id')->all());

        $this->assertNotNull($consumed->fresh());
        $this->assertNotNull($cited->fresh());
        $this->assertNotNull($noWindow->fresh());
        $this->assertNotNull($stillLive->fresh());
    }

    #[Test]
    public function reaping_a_signal_keeps_what_it_produced(): void
    {
        $signal = Signal::factory()->create();

        $post = ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'social_band' => SocialBand::Reaction,
            'signal_id' => $signal->getKey(),
        ]);
        $interaction = Interaction::factory()->create(['signal_id' => $signal->getKey()]);

        $signal->delete();

        // Documented in three places and asserted in none until now: nullOnDelete
        // on both sides. A published post and a conversation still owed a reply
        // must both outlive the reason they exist, or the reaper of §5 becomes
        // a way to delete the channel's history.
        $this->assertNull($post->refresh()->signal_id);
        $this->assertNull($interaction->refresh()->signal_id);
    }

    #[Test]
    public function consuming_a_signal_takes_it_out_of_the_queue(): void
    {
        $signal = Signal::factory()->create();

        $this->assertSame(1, Signal::query()->live()->count());

        $signal->markConsumed();

        $this->assertNotNull($signal->refresh()->consumed_at);
        $this->assertSame(0, Signal::query()->live()->count());
    }

    #[Test]
    public function a_signal_knows_what_it_produced(): void
    {
        $signal = Signal::factory()->kind(SignalKind::News)->create();

        ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'signal_id' => $signal->getKey(),
            'social_band' => SocialBand::Reaction,
        ]);
        Interaction::factory()->create(['signal_id' => $signal->getKey()]);

        // §3: the loop learns by source, and in a month "does the news contour
        // pay for itself" is a group by rather than an argument.
        $this->assertSame(1, $signal->contentItems()->count());
        $this->assertSame(1, $signal->interactions()->count());
        $this->assertSame(SignalKind::News, $signal->refresh()->kind);
    }

    // ------------------------------------------------------- interactions

    #[Test]
    public function a_conversation_walks_from_new_to_answered(): void
    {
        Carbon::setTestNow('2026-08-08 09:15:00');

        $interaction = Interaction::factory()->create([
            'received_at' => Carbon::now()->subMinutes(7),
        ]);

        $this->assertSame(InteractionState::New, $interaction->state);

        $interaction->markDrafted('Thanks — we clean every six weeks in Lisbon.');

        $this->assertSame(InteractionState::Drafted, $interaction->state);
        $this->assertNotNull($interaction->draft_generated_at);
        $this->assertNull($interaction->answered_at);

        $interaction->markAnswered('17899999999');

        $this->assertSame(InteractionState::Answered, $interaction->refresh()->state);
        $this->assertSame('17899999999', $interaction->reply_external_id);

        // The latency §4.2 is judged on is `answered_at - received_at`, so the
        // stamp is made by the machine rather than by the caller — and it is
        // asserted as a value rather than as "not null", because a stamp that
        // is merely present can still be the wrong moment and the number this
        // contour is measured on would be quietly wrong with it.
        $this->assertNotNull($interaction->answered_at);
        $this->assertTrue(Carbon::parse('2026-08-08 09:15:00')->equalTo($interaction->answered_at));
        $this->assertSame(7, (int) $interaction->received_at->diffInMinutes($interaction->answered_at));
    }

    #[Test]
    public function a_draft_written_over_an_answer_is_refused(): void
    {
        Carbon::setTestNow('2026-08-08 09:15:00');

        $conversation = Interaction::factory()->create();

        // Two instances of one row, which is the whole scenario: the drafting
        // job read it and went into a twenty-second model call.
        $drafter = Interaction::query()->whereKey($conversation->getKey())->firstOrFail();
        $operator = Interaction::query()->whereKey($conversation->getKey())->firstOrFail();

        $operator->markDrafted('A reply the operator wrote by hand.');
        $operator->markAnswered('17899999999');

        Carbon::setTestNow('2026-08-08 09:15:20');

        try {
            // The job comes back holding `new`. new → drafted is a legal edge,
            // so nothing in the state machine objects; only the predicate on
            // the write does.
            $drafter->markDrafted('The model finally answered.');
            $this->fail('Expected the stale write to be refused.');
        } catch (ConcurrentStateChange $e) {
            $this->assertStringContainsString(
                'This conversation was answered while a reply was being drafted',
                $e->getMessage(),
            );
        }

        $fresh = $conversation->fresh();

        // Without the predicate this row would be back in `drafted` — with
        // `answered_at` and `reply_external_id` still underneath it — the
        // conversation would return to the duty queue carrying a fresh draft,
        // and a second reply would go into a thread that already had one.
        $this->assertNotNull($fresh);
        $this->assertSame(InteractionState::Answered, $fresh->state);
        $this->assertSame('17899999999', $fresh->reply_external_id);
        $this->assertSame('A reply the operator wrote by hand.', $fresh->draft_reply);
        $this->assertTrue(Carbon::parse('2026-08-08 09:15:00')->equalTo($fresh->answered_at));

        $this->assertSame(0, Interaction::query()->open()->count());
    }

    #[Test]
    public function discarding_a_draft_takes_the_reply_text_with_it(): void
    {
        $interaction = Interaction::factory()->inState(InteractionState::Drafted)->create();

        $interaction->discardDraft();

        $fresh = $interaction->fresh();

        // InteractionState's own docblock says why this edge exists: a draft
        // the operator throws away returns the conversation to the queue
        // unanswered, which is honest. Leaving the text behind is not — the
        // duty screen would show a `new` conversation carrying a reply nobody
        // intends to send, and the next drafting run has no reason to replace
        // something that is already there.
        $this->assertNotNull($fresh);
        $this->assertSame(InteractionState::New, $fresh->state);
        $this->assertNull($fresh->draft_reply);
        $this->assertNull($fresh->draft_generated_at);

        $this->assertSame([$interaction->getKey()], Interaction::query()->open()->pluck('id')->all());
    }

    #[Test]
    public function a_request_body_cannot_stamp_a_reply_that_was_never_sent(): void
    {
        $interaction = Interaction::factory()->create();

        // `answered_at` and `reply_external_id` are the evidence that a reply
        // left the building, and the state machine is the only thing allowed to
        // write them. A webhook body reaching either could make a conversation
        // look answered without one, which takes it out of the duty queue.
        foreach (['state', 'answered_at', 'reply_external_id'] as $guarded) {
            try {
                $interaction->fill([$guarded => 'anything at all']);
                $this->fail("Expected [{$guarded}] to be refused by mass assignment.");
            } catch (MassAssignmentException) {
                // Exactly what should happen.
            }
        }

        $fresh = $interaction->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(InteractionState::New, $fresh->state);
        $this->assertNull($fresh->answered_at);
        $this->assertNull($fresh->reply_external_id);
    }

    #[Test]
    public function a_conversation_cannot_be_answered_before_it_has_a_draft(): void
    {
        $interaction = Interaction::factory()->create();

        try {
            $interaction->transitionTo(InteractionState::Answered);
            $this->fail('Expected the transition to be refused.');
        } catch (InvalidStateTransition $e) {
            // §4.2 forbids autopublish in this contour permanently, and the
            // shape of that rule in the machine is that a reply has to exist
            // and be looked at before it can be recorded as sent.
            $this->assertStringContainsString('new', $e->getMessage());
            $this->assertStringContainsString('answered', $e->getMessage());
        }

        $this->assertSame(InteractionState::New, $interaction->fresh()?->state);
    }

    #[Test]
    public function the_two_finished_states_are_finished(): void
    {
        foreach ([InteractionState::Answered, InteractionState::Ignored] as $terminal) {
            $this->assertTrue($terminal->isTerminal());
            $this->assertSame([], $terminal->allowedNext());

            foreach (InteractionState::cases() as $next) {
                $interaction = Interaction::factory()->inState($terminal)->create();

                try {
                    $interaction->transitionTo($next);
                    $this->fail("Expected {$terminal->value} → {$next->value} to be refused.");
                } catch (InvalidStateTransition $e) {
                    $this->assertStringContainsString('nothing', $e->getMessage());
                }

                // §3 calls this a duty queue rather than a log, so a row that
                // can never leave the queue is a bug — but a row that leaves it
                // twice is the one that sends a second reply.
                $this->assertSame($terminal, $interaction->fresh()?->state);
            }
        }
    }

    #[Test]
    public function an_answered_conversation_cannot_be_re_drafted(): void
    {
        $interaction = Interaction::factory()->inState(InteractionState::Answered)->create();

        try {
            $interaction->transitionTo(InteractionState::Drafted);
            $this->fail('Expected the transition to be refused.');
        } catch (InvalidStateTransition $e) {
            // Same exception as the content machine, same message shape: a
            // caller catching it should not have to know which table it read.
            $this->assertStringContainsString('answered', $e->getMessage());
            $this->assertStringContainsString('drafted', $e->getMessage());
            $this->assertStringContainsString('nothing', $e->getMessage());
        }

        $this->assertSame(InteractionState::Answered, $interaction->fresh()?->state);
    }

    #[Test]
    public function a_conversation_can_be_left_alone_with_a_reason(): void
    {
        $interaction = Interaction::factory()->create();

        $interaction->ignore('spam');

        // §7: the explanation next to the silence is mandatory — a machine that
        // says nothing is indistinguishable from a broken one.
        $this->assertSame(InteractionState::Ignored, $interaction->refresh()->state);
        $this->assertSame('spam', $interaction->ignored_reason);
        $this->assertTrue($interaction->state->isTerminal());

        // Nothing was sent, so nothing is stamped. `answered_at` is the input to
        // the latency §4.2 is judged on, and a conversation deliberately left
        // alone would otherwise report a reply time it never earned.
        $this->assertNull($interaction->answered_at);
        $this->assertNull($interaction->reply_external_id);
    }

    #[Test]
    public function a_conversation_cannot_be_filed_under_another_projects_channel(): void
    {
        $other = Project::factory()->create();

        $theirChannel = app(CurrentProject::class)->run(
            $other,
            fn (): Channel => Channel::factory()->social(ChannelType::Threads)->create(),
        );

        $this->expectException(QueryException::class);

        // Reachable from a webhook payload, and BelongsToProject cannot see it:
        // it compares project_id against the current tenant and has no opinion
        // about the channel. The row that results is worse than untidy —
        // `$interaction->channel` resolves through the tenant scope, finds the
        // channel in the other project, and comes back null, so every reply
        // path fatals and the conversation sits in the duty queue permanently
        // unanswerable. Only the composite foreign key can state the rule once.
        Interaction::factory()->create([
            'channel_id' => $theirChannel->getKey(),
            'project_id' => $this->project->getKey(),
        ]);
    }

    #[Test]
    public function disconnecting_a_channel_takes_its_conversations_with_it(): void
    {
        $channel = Channel::factory()->social(ChannelType::Threads)->create();
        $survivor = Channel::factory()->social(ChannelType::LinkedIn)->create();

        $doomed = $this->interaction($channel);
        $kept = $this->interaction($survivor);

        $channel->delete();

        // The intent the migration writes down and nothing asserted: a
        // conversation is unanswerable without the credentials that reached it,
        // and an orphan queue nobody can act on is worse than an empty one.
        $this->assertNull(Interaction::query()->find($doomed->getKey()));
        $this->assertNotNull(Interaction::query()->find($kept->getKey()));
    }

    #[Test]
    public function the_duty_queue_holds_what_is_still_owed_oldest_first(): void
    {
        $channel = Channel::factory()->social(ChannelType::Threads)->create();

        $oldest = $this->interaction($channel, ['received_at' => Carbon::now()->subHours(4)]);
        $newest = $this->interaction($channel, ['received_at' => Carbon::now()->subMinutes(5)]);
        $middle = $this->interaction($channel, ['received_at' => Carbon::now()->subHour()]);
        $middle->markDrafted('A draft still waiting for a human.');

        $answered = $this->interaction($channel, ['received_at' => Carbon::now()->subHours(2)]);
        $answered->markDrafted('Sent already.');
        $answered->markAnswered('17811111111');

        $this->interaction($channel, ['received_at' => Carbon::now()->subHours(3)])
            ->ignore('not about us');

        // Oldest first, which is the opposite of every other list in the engine
        // and is the point: a queue sorted newest first buries the conversation
        // that has been waiting longest, and waiting is the metric.
        $this->assertSame(
            [$oldest->getKey(), $middle->getKey(), $newest->getKey()],
            Interaction::query()->open()->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_reply_delivered_twice_is_stored_once(): void
    {
        $channel = Channel::factory()->social(ChannelType::Threads)->create();

        $this->interaction($channel, ['external_id' => '17877777777']);

        $this->expectException(QueryException::class);

        // §4.1 runs a webhook and a poll over the same thread. Without the
        // index the operator sees the conversation twice and answers it twice.
        $this->interaction($channel, ['external_id' => '17877777777']);
    }

    #[Test]
    public function the_same_reply_id_is_free_on_another_channel(): void
    {
        $first = Channel::factory()->social(ChannelType::Threads)->create();
        $second = Channel::factory()->social(ChannelType::LinkedIn)->create();

        $this->interaction($first, ['external_id' => '17877777777']);
        $other = $this->interaction($second, ['external_id' => '17877777777']);

        $this->assertSame('17877777777', $other->external_id);
    }

    // ------------------------------------------------------ project state

    #[Test]
    public function a_project_has_one_state_per_day(): void
    {
        ProjectState::factory()->on('2026-08-01')->create();

        $this->expectException(QueryException::class);

        // Re-capturing a day must correct it rather than double it: the sweep
        // is idempotent by this constraint and by nothing else, and a retried
        // job is the normal case.
        ProjectState::factory()->on('2026-08-01')->create();
    }

    #[Test]
    public function a_reply_rate_is_null_when_there_was_nothing_to_divide_by(): void
    {
        $unmeasured = ProjectState::factory()->on('2026-08-01')->create([
            'post_impressions' => null,
            'post_replies' => null,
        ]);

        $silent = ProjectState::factory()->on('2026-08-02')->create([
            'post_impressions' => 0,
            'post_replies' => 0,
        ]);

        $measured = ProjectState::factory()->on('2026-08-03')->create([
            'post_impressions' => 1_000,
            'post_replies' => 25,
        ]);

        // The governor cuts frequency when the trailing rate falls below a
        // threshold. Reporting 0.0 for a day nobody measured would throttle a
        // project for a missing API call.
        $this->assertNull($unmeasured->replyRate());
        $this->assertNull($silent->replyRate());
        $this->assertSame(0.025, $measured->replyRate());
    }

    #[Test]
    public function a_reply_rate_is_null_when_the_numbers_cannot_both_be_true(): void
    {
        // Not persisted: the CHECK below now keeps a negative out of the table.
        // This is the other half — a column constraint added after the data
        // does not clean the rows that got in before it, and replyRate() is
        // what the governor actually reads.
        $impossible = ProjectState::factory()->make([
            'post_impressions' => -5,
            'post_replies' => 3,
        ]);

        // -0.6 is below every threshold there is, so §4.3's governor would have
        // cut this project's frequency and gone on cutting it.
        $this->assertNull($impossible->replyRate());

        $contradictory = ProjectState::factory()->make([
            'post_impressions' => 10,
            'post_replies' => 32,
        ]);

        // More replies than impressions cannot be one day's figures, so it is
        // not a measurement — it is two reads of different windows. Null means
        // "not measured" everywhere else on this row and the governor skips the
        // day; a 3.2 would be averaged in and hold the trailing rate above the
        // threshold on the strength of a broken number.
        $this->assertNull($contradictory->replyRate());
    }

    #[Test]
    public function the_database_refuses_a_counter_below_zero(): void
    {
        $this->expectException(QueryException::class);

        // `unsignedInteger` is a no-op in Laravel's Postgres grammar — a plain
        // `integer` with no CHECK — so the column type read as a constraint and
        // was a comment. §4.3's governor is downstream of these numbers.
        ProjectState::factory()->on('2026-08-01')->create(['post_impressions' => -5]);
    }

    #[Test]
    public function the_same_day_is_free_in_another_project(): void
    {
        ProjectState::factory()->on('2026-08-01')->create();

        $other = Project::factory()->create();

        // The other leg of the index, and the one a daily sweep across every
        // project hits on its second row: two projects captured on the same day
        // is not a collision, it is Tuesday.
        $theirs = app(CurrentProject::class)->run(
            $other,
            fn (): ProjectState => ProjectState::factory()->on('2026-08-01')->create(),
        );

        $this->assertTrue($theirs->captured_on->isSameDay(Carbon::parse('2026-08-01')));
    }

    // -------------------------------------------------------------- bands

    #[Test]
    public function two_bands_can_never_reach_autopublish_and_four_can_earn_it(): void
    {
        // False here means "никогда", not "not yet". A reply to a living person
        // sent as the brand with no human in the loop is refused permanently by
        // §4.2; a comment on news is the one place where being wrong is both
        // fast and public, and §5 marks it never for the same reason its drafts
        // are killed rather than published late.
        $this->assertFalse(SocialBand::Conversation->canEverAutopublish());
        $this->assertFalse(SocialBand::Reaction->canEverAutopublish());

        $this->assertTrue(SocialBand::Question->canEverAutopublish());
        $this->assertTrue(SocialBand::OwnData->canEverAutopublish());
        $this->assertTrue(SocialBand::Season->canEverAutopublish());
        $this->assertTrue(SocialBand::Derivative->canEverAutopublish());
    }

    #[Test]
    public function the_weekly_budgets_are_the_table_in_the_spec(): void
    {
        // §5's table, column by column. The numbers are a ceiling and never a
        // plan — "недобор допустим, перебор — нет" — so a budget that drifted
        // upward by one would not fail anything except the account.
        $this->assertSame(2, SocialBand::Question->weeklyBudget());
        $this->assertSame(1, SocialBand::OwnData->weeklyBudget());
        $this->assertSame(1, SocialBand::Season->weeklyBudget());
        $this->assertSame(1, SocialBand::Reaction->weeklyBudget());
        $this->assertSame(1, SocialBand::Derivative->weeklyBudget());

        $this->assertNull(SocialBand::Conversation->weeklyBudget());
    }

    #[Test]
    public function the_uncounted_band_has_to_be_asked_before_it_is_compared(): void
    {
        $this->assertFalse(SocialBand::Conversation->isCounted());

        foreach ([SocialBand::Question, SocialBand::OwnData, SocialBand::Season, SocialBand::Reaction, SocialBand::Derivative] as $counted) {
            $this->assertTrue($counted->isCounted());
        }

        // The trap the method exists to close, spelled out: the obvious
        // governor check reads `$count >= $band->weeklyBudget()`, and against a
        // null that is `$count >= 0` — true for zero posts. "Без счёта" becomes
        // "never", silently, in the direction that shuts off the half of the
        // channel §1 says is worth the most.
        $this->assertTrue(SocialBand::Conversation->weeklyBudget() <= 0);
    }

    // ------------------------------------------------------------ tenancy

    #[Test]
    public function the_new_tables_are_invisible_across_the_tenant_boundary(): void
    {
        $other = Project::factory()->create();
        $tenant = app(CurrentProject::class);

        $signal = $tenant->run($other, fn (): Signal => Signal::factory()->create());
        $interaction = $tenant->run($other, fn (): Interaction => Interaction::factory()->create());
        $state = $tenant->run($other, fn (): ProjectState => ProjectState::factory()->create());

        // Every read here carries the tenant scope, and the social tables are
        // the ones an operator screen paginates without thinking about it.
        $this->assertSame(0, Signal::query()->count());
        $this->assertSame(0, Interaction::query()->count());
        $this->assertSame(0, ProjectState::query()->count());

        $this->assertNull(Signal::query()->find($signal->getKey()));
        $this->assertNull(Interaction::query()->find($interaction->getKey()));
        $this->assertNull(ProjectState::query()->find($state->getKey()));

        // …and they are there, under the project that owns them.
        $this->assertSame(1, Signal::acrossProjects()->whereKey($signal->getKey())->count());
        $this->assertSame($other->getKey(), $interaction->project_id);
        $this->assertSame($other->getKey(), $state->project_id);
    }

    /** @param array<string, mixed> $attributes */
    private function interaction(Channel $channel, array $attributes = []): Interaction
    {
        return Interaction::factory()->create([
            'channel_id' => $channel->getKey(),
            ...$attributes,
        ]);
    }
}
