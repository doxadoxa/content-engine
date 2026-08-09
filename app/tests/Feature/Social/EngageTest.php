<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Console\Commands\EngineTickCommand;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\InteractionSkipReason;
use App\Enums\InteractionState;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Enums\ReplyAttemptOutcome;
use App\Enums\SocialBand;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\InteractionReplyAttempt;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\User;
use App\Pipelines\Definitions\SocialEngagePipeline;
use App\Pipelines\Steps\SocialEngage\FactCheckReply;
use App\Publishing\ThreadsRateLimiter;
use App\Social\Exceptions\ReplyRefused;
use App\Social\InteractionReplySender;
use App\Social\ReplyRouting;
use App\Support\Tenancy\CurrentProject;
use Database\Factories\ProjectIntegrationFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Phase 12.4 — §4.2, the engage contour end to end.
 *
 * The contour is one sentence of §4.2 — "вебхук → черновик ответа → оператор
 * подтверждает в одно касание → отправка" — with a permanent prohibition
 * attached to it, and the prohibition is what most of this file is about:
 *
 * > **Автопаблиш здесь запрещён навсегда**, не «пока не заслужит»: ответ живому
 * > человеку от имени бренда без человека в контуре — это не экономия рутины,
 * > это способ однажды очень плохо пошутить.
 *
 * A test that asserted "the current code does not autopublish" would pass
 * today and say nothing about tomorrow, so the first test below asserts the
 * *mechanisms* instead: the source tree, the state machine, the band, and the
 * end of the drafting path. Each of them has to be dismantled deliberately for
 * a reply to leave the building without a person, which is the difference
 * between a prohibition and a default.
 *
 * Everything else here is §7 — the duty screen and the four things done on it —
 * and §11.1, whose open question decides whether the operator's one tap is an
 * API call or a copy-paste. Both answers are tested, because the flag is a
 * record of a fact about the platform rather than a preference, and the day it
 * flips nothing else should have to change.
 *
 * Nothing reaches the network. `Http::preventStrayRequests()` is on globally.
 */
final class EngageTest extends TestCase
{
    use RefreshDatabase;

    /** What {@see ProjectIntegrationFactory::threads()} connects. */
    private const string USER_ID = '17841400000000000';

    private const string WEBHOOK = '/api/threads/webhook';

    private const string SECRET = 'threads-webhook-secret';

    /** The reply the engine writes, unless a test says otherwise. */
    private const string DRAFT = 'Vinegar works on glass, but it eats marble — use soap on the sill instead.';

    private Project $project;

    private User $operator;

    private Channel $channel;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-09 12:00:00');

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        // A member, not the owner. §4.2's latency is unreachable if the only
        // person allowed to press Send is the account holder, so these routes
        // carry no `project.owner` — exactly like `content.approve`.
        $this->operator = User::factory()->create();
        $this->operator->projects()->attach($this->project, ['role' => 'member']);

        config()->set('services.threads.client_id', 'threads-app-id');
        config()->set('services.threads.client_secret', 'threads-app-secret');
        config()->set('services.threads.webhook_secret', self::SECRET);
        config()->set('services.threads.webhook_verify_token', 'threads-verify-token');
        config()->set('queue.default', 'sync');

        $this->channel = Channel::factory()->threads()->create(['verified_at' => now()]);
        ProjectIntegration::factory()->threads()->create();

        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;
        $this->models->willAnswerRole('draft', self::DRAFT);
        $this->models->willAnswerRole('factcheck', 'PASS');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ §4.2

    /**
     * The central test of this phase: no automated path can send a reply.
     *
     * Four independent mechanisms, asserted separately because the point is
     * that removing any one of them still leaves three.
     */
    #[Test]
    public function no_automated_path_can_send_a_reply(): void
    {
        // 1. The sender demands a User *and asks the runtime whether it is
        //    really them*. The type alone was documentation — `User::first()`
        //    is one line — so the mechanism is tested where it now lives, in
        //    `a_scheduled_task_cannot_send_a_reply_even_holding_a_user()`.
        //    Here: nobody but the controller may even reach the class.
        foreach (['Console', 'Pipelines', 'Social/Jobs', 'Publishing'] as $directory) {
            $this->assertSame(
                [],
                $this->filesMentioning('InteractionReplySender', "app/{$directory}"),
                "app/{$directory} must not know that App\\Social\\InteractionReplySender exists (§4.2).",
            );
        }

        // And across the whole project — not an allowlist of four directories
        // under `app/`, which is the shape this assertion used to have and the
        // shape that missed the file §11.3 actually points at. Four lines in
        // `routes/console.php` defeat all four mechanisms:
        //
        //     Schedule::call(fn () => Interaction::query()->open()->each(
        //         fn ($i) => app(InteractionReplySender::class)
        //             ->send($i, $i->project, User::first(), (string) $i->draft_reply)
        //     ))->everyMinute();
        //
        // and `routes/` is not under `app/`. So the scan is the whole tree with
        // a denylist, and the only two files that *use* the class — as opposed
        // to naming it in a docblock or importing it so `{@see}` resolves — are
        // the class itself and the controller behind `auth`.
        $this->assertSame(
            [
                'app/Http/Controllers/InteractionController.php',
                'app/Social/InteractionReplySender.php',
            ],
            $this->filesUsing('InteractionReplySender'),
        );

        // 2. The state machine has no *single* edge to `answered`, which is a
        //    smaller claim than it used to make here and the true one:
        //    `markDrafted()` then `markAnswered()` is two public calls, so this
        //    does not stop automation. What it stops is a lost answer under a
        //    race — `transitionTo()` is compare-and-set on the previous state.
        $this->assertFalse(InteractionState::New->canTransitionTo(InteractionState::Answered));
        $this->assertSame(
            [InteractionState::Drafted, InteractionState::Ignored],
            InteractionState::New->allowedNext(),
        );

        // 3. The band refuses it, permanently rather than until it earns it.
        $this->assertFalse(SocialBand::Conversation->canEverAutopublish());

        // 4. And the whole automated contour, run for real, stops at a draft:
        //    an event arrives, the pipeline runs to completion, and not one
        //    request goes to Threads.
        Http::fake();

        $conversation = $this->arrive();

        $this->assertSame(InteractionState::Drafted, $conversation->state);
        $this->assertSame(self::DRAFT, $conversation->draft_reply);
        $this->assertNull($conversation->answered_at);
        $this->assertNull($conversation->reply_external_id);

        Http::assertNothingSent();
    }

    /**
     * The scheduled-task attack, run for real.
     *
     * These are the four lines the structural test above now scans
     * `routes/console.php` for, and the reason scanning is not enough: a
     * denylist catches them in review, and this catches them at runtime. The
     * `User` parameter was described as "the seam automation cannot cross" and
     * was documentation — `User::first()` is one line, and nothing in `send()`
     * asked whether anybody was authenticated.
     */
    #[Test]
    public function a_scheduled_task_cannot_send_a_reply_even_holding_a_user(): void
    {
        $this->fakeApi();

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        // No `actingAs`, which is the whole point: a scheduled task, a queue
        // job and a console command have no authenticated user, and the object
        // they can look up is not one.
        $sender = app(InteractionReplySender::class);

        try {
            $sender->send($conversation, $this->project, User::firstOrFail(), (string) $conversation->draft_reply);

            $this->fail('An unauthenticated caller sent a reply. §4.2 forbids that permanently.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('§4.2', $e->getMessage());
        }

        $this->assertSame(InteractionState::Drafted, $conversation->refresh()->state);
        $this->assertSame([], $this->calls('/threads'));
        $this->assertSame(0, InteractionReplyAttempt::query()->count());

        // Recording one by hand is refused for the same reason: it closes the
        // row, and a row closed by nobody is the same lie.
        $this->expectException(LogicException::class);

        $sender->recordSentByHand($conversation, $this->project, User::firstOrFail(), 'anything', null);
    }

    /**
     * And an operator cannot be claimed by somebody who is not them.
     *
     * The controller always passes `$request->user()`, so this cannot fire
     * through the screen — which is the point. It is the second half of the
     * runtime seam, and it is what stops a future caller from authenticating as
     * one person and recording the answer against another.
     */
    #[Test]
    public function a_reply_cannot_be_attributed_to_an_operator_who_is_not_the_one_signed_in(): void
    {
        $this->fakeApi();

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        $somebody = User::factory()->create();
        $somebody->projects()->attach($this->project, ['role' => 'member']);

        $this->actingAs($this->operator);

        $this->expectException(LogicException::class);

        app(InteractionReplySender::class)
            ->send($conversation, $this->project, $somebody, (string) $conversation->draft_reply);
    }

    #[Test]
    public function a_webhook_event_becomes_a_draft_with_no_scheduled_command_running(): void
    {
        Http::fake();

        // The engine is busy with an article, which is precisely the situation
        // `EngineTickCommand::isBusy()` refuses to start anything in. §4.2 is
        // measured in minutes, so this contour does not wait for it.
        PipelineRun::factory()->pending()->create(['pipeline' => 'generation']);

        $conversation = $this->arrive();

        $this->assertSame(InteractionState::Drafted, $conversation->state);
        $this->assertSame(self::DRAFT, $conversation->draft_reply);

        $run = PipelineRun::query()->where('pipeline', SocialEngagePipeline::key())->sole();

        $this->assertSame(PipelineRunStatus::Completed, $run->status);

        // Nothing on the clock started it, and nothing on the clock could.
        // `social:listen` is on the schedule and this is not: §4.1 is hourly
        // work, §4.2 is an event, and a reply queued behind the next tick is a
        // reply that misses the hour it was worth writing for.
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertStringNotContainsString(
                'engage',
                (string) ($event->command ?? ''),
                'The engage contour is started by an event, not by the clock (§4.2).',
            );
        }

        $contour = new \ReflectionClassConstant(EngineTickCommand::class, 'CONTOUR');

        $this->assertNotContains(SocialEngagePipeline::key(), (array) $contour->getValue());
    }

    // ------------------------------------------------------- §4.2, one tap

    #[Test]
    public function one_tap_moves_the_conversation_from_new_through_drafted_to_answered(): void
    {
        $this->fakeApi();

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        // new → drafted happened without anybody touching it.
        $this->assertSame(InteractionState::Drafted, $conversation->state);

        Carbon::setTestNow('2026-08-09 12:04:00');

        $this->send($conversation, self::DRAFT)->assertRedirect();

        $answered = $conversation->refresh();

        $this->assertSame(InteractionState::Answered, $answered->state);
        $this->assertSame('post-1', $answered->reply_external_id);
        $this->assertNotNull($answered->answered_at);
        // §4.2 is judged on this number, so the row has to be able to answer it.
        $this->assertSame(
            240,
            (int) $answered->received_at->diffInSeconds($answered->answered_at),
        );

        // Threaded under *their* reply rather than under the post, because we
        // are answering the person.
        $container = $this->calls('/threads');

        $this->assertCount(1, $container);
        $this->assertSame(self::DRAFT, $container[0]['text']);
        $this->assertSame('reply-1', $container[0]['reply_to_id']);
        $this->assertCount(1, $this->calls('/threads_publish'));

        // And it has left the duty queue.
        $this->assertSame(0, Interaction::query()->open()->count());
    }

    #[Test]
    public function the_operator_can_edit_the_draft_before_sending_it(): void
    {
        $this->fakeApi();

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        $edited = 'Vinegar is fine on the glass. Keep it off the marble sill.';

        $this->send($conversation, $edited)->assertRedirect();

        // What went out is what the operator was looking at, not what the
        // engine proposed…
        $this->assertSame($edited, $this->calls('/threads')[0]['text']);

        // …and the row says the same thing, so the queue and the thread agree.
        $this->assertSame($edited, $conversation->refresh()->draft_reply);
    }

    // ---------------------------------------------------------------- §7 skip

    #[Test]
    public function skipping_requires_a_reason_and_records_it(): void
    {
        Http::fake();

        $conversation = $this->arrive();

        // §7 makes the explanation beside the silence mandatory.
        $this->actingAs($this->operator)
            ->post(route('engage.skip', $conversation), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame(InteractionState::Drafted, $conversation->refresh()->state);

        $this->actingAs($this->operator)
            ->post(route('engage.skip', $conversation), ['reason' => InteractionSkipReason::NotForUs->value])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $skipped = $conversation->refresh();

        $this->assertSame(InteractionState::Ignored, $skipped->state);
        // The enum's value, not a sentence: §7's reason is countable or it is
        // not a signal about the listening contour's filtering.
        $this->assertSame(InteractionSkipReason::NotForUs->value, $skipped->ignored_reason);
        $this->assertSame(0, Interaction::query()->open()->count());

        Http::assertNothingSent();
    }

    // --------------------------------------------------------------- §7 guard

    #[Test]
    public function a_guard_refused_draft_is_shown_with_its_reason_rather_than_hidden(): void
    {
        $this->fakeApi();

        // A topic the Brand Brief says this project never writes about (§4.3).
        // Chosen over the length rule because the form request also measures
        // length: what has to be proven here is that the *guard* refuses at the
        // send boundary, not that a maxlength attribute does.
        BrandBrief::factory()->create(['forbidden_topics' => ['guaranteed returns']]);

        $refused = 'You get guaranteed returns on every trade, no exceptions.';
        $this->models->willAnswerRole('draft', $refused);

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        // Stored, not dropped. A conversation the engine silently gave up on
        // is indistinguishable from one it never reached (§7).
        $this->assertSame(InteractionState::Drafted, $conversation->state);
        $this->assertSame($refused, $conversation->draft_reply);

        $this->screen()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engage/index')
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $conversation->getKey())
            // Shown with its reason, and the reason is a sentence rather than
            // a flag: §7 wants the explanation beside the silence.
            ->where('conversations.data.0.draft', $refused)
            ->where('conversations.data.0.sendable', false)
            ->has('conversations.data.0.findings', 1)
            ->where('conversations.data.0.findings.0.code', 'forbidden_topic')
            ->where('conversations.data.0.findings.0.blocking', true)
        );

        // Shown is not sendable: the guard runs again at the boundary, so even
        // a client that never rendered the screen is refused.
        $this->send($conversation, $refused)->assertSessionHasErrors('reply');

        $this->assertSame(InteractionState::Drafted, $conversation->refresh()->state);
        $this->assertSame([], $this->calls('/threads'));

        // And it is undone by the operator editing the words, which is exactly
        // the decision the human is in this loop to make.
        $this->send($conversation, 'Nothing is guaranteed; here is how the fee works.')
            ->assertSessionHasNoErrors();

        $this->assertSame(InteractionState::Answered, $conversation->refresh()->state);
    }

    // ---------------------------------------------------------------- §10 YMYL

    #[Test]
    public function a_ymyl_projects_reply_is_factchecked(): void
    {
        $this->fakeApi();

        $this->project->forceFill([
            'is_ymyl' => true,
            'original_data' => ['fee' => 'We charge 1.4% per trade.'],
        ])->save();

        $this->models->willAnswerRole('factcheck', 'The reply claims a 0.2% fee, which no fact supports.');

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        $run = PipelineRun::query()->where('pipeline', SocialEngagePipeline::key())->sole();
        $step = $run->steps()->where('step_key', FactCheckReply::key())->sole();

        // Ran, rather than skipped — §10 calls it obligatory for a YMYL
        // project, "как в генерации".
        $this->assertSame(PipelineStepStatus::Succeeded, $step->status);
        $this->assertTrue((bool) ($step->output['required'] ?? false));
        $this->assertFalse((bool) ($step->output['passed'] ?? true));

        // The finding reaches the operator, blocks the one-tap send, and says
        // it is the kind of block one tap can clear.
        $this->screen()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversations.data.0.sendable', false)
            ->where('conversations.data.0.findings.0.code', 'factcheck')
            ->where('conversations.data.0.findings.0.blocking', true)
            ->where('conversations.data.0.findings.0.acknowledgeable', true)
        );

        $this->send($conversation, self::DRAFT)->assertSessionHasErrors('reply');
        $this->assertSame(InteractionState::Drafted, $conversation->refresh()->state);

        // The one character that used to clear it. The rule was
        // `is_ymyl && trim($text) === trim($draft)`, so a full stop on the end
        // deleted §10's obligatory step while leaving the unsupported number
        // exactly where it was.
        $this->send($conversation, self::DRAFT.'.')->assertSessionHasErrors('reply');
        $this->assertSame(InteractionState::Drafted, $conversation->refresh()->state);

        // And rewriting it does not clear it either, which is the same
        // correction from the other side: §10 calls the fact-check obligatory
        // for the reply that goes out, and an operator's own sentence about a
        // fee is exactly the sentence it is obligatory about.
        $this->send($conversation, 'Our fee is 1.4% per trade.')->assertSessionHasErrors('reply');
        $this->assertSame(InteractionState::Drafted, $conversation->refresh()->state);

        // What clears it is a person saying they looked. One tap — §4.2's
        // budget — and it is written down, which the diff never was.
        $this->send($conversation, 'Our fee is 1.4% per trade.', ['factcheck'])
            ->assertSessionHasNoErrors();

        $this->assertSame(InteractionState::Answered, $conversation->refresh()->state);

        $attempt = InteractionReplyAttempt::query()->sole();

        $this->assertSame(ReplyAttemptOutcome::Delivered, $attempt->outcome);
        $this->assertSame(['factcheck'], $attempt->acknowledged);
        $this->assertSame($this->operator->getKey(), $attempt->user_id);
        $this->assertSame('Our fee is 1.4% per trade.', $attempt->text);
    }

    #[Test]
    public function on_a_ymyl_project_a_reply_the_engine_never_drafted_is_factchecked_too(): void
    {
        $this->fakeApi();

        $this->project->forceFill(['is_ymyl' => true])->save();

        // The drafting run never landed. This is the hole the old rule had no
        // way to see: with no draft there is nothing for `$text === $draft` to
        // be false against, so an operator's own words on a YMYL project went
        // out with no fact-check anywhere in the contour.
        $conversation = Interaction::factory()->create([
            'channel_id' => $this->channel->getKey(),
            'root_external_id' => 'root-1',
            'received_at' => now()->subMinutes(4),
        ]);
        $this->ourPost('root-1');

        $this->assertSame(InteractionState::New, $conversation->state);
        $this->assertNull($conversation->draft_reply);

        // §7 says so on the row rather than showing an empty box and nothing.
        $this->screen()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversations.data.0.state', 'new')
            ->where('conversations.data.0.state_label', 'New')
            ->where('conversations.data.0.draft', null)
            ->where('conversations.data.0.findings.0.code', 'factcheck')
            ->where('conversations.data.0.findings.0.blocking', true)
        );

        $mine = 'Our fee is 1.4% per trade.';

        $this->send($conversation, $mine)->assertSessionHasErrors('reply');
        $this->assertSame(InteractionState::New, $conversation->refresh()->state);

        $this->send($conversation, $mine, ['factcheck'])->assertSessionHasNoErrors();

        // And the whole adopt branch: new → drafted → answered, with the
        // operator's words as the draft, because a conversation cannot go from
        // untouched to answered without a reply existing somewhere.
        $answered = $conversation->refresh();

        $this->assertSame(InteractionState::Answered, $answered->state);
        $this->assertSame($mine, $answered->draft_reply);
        $this->assertNotNull($answered->draft_generated_at);
        $this->assertSame($mine, $this->calls('/threads')[0]['text']);
    }

    // ------------------------------------------------------- two operators

    /**
     * Two sequential requests, which is a compare-and-set test and not a lock
     * test — hence the name.
     *
     * Deleting `lockForUpdate()` left this green, because nothing here is
     * concurrent: the first request finishes before the second starts, so what
     * refuses the second is `transitionTo()`'s `where state = ?`. That is worth
     * asserting and it is what this asserts. The lock itself is a separate
     * claim with a separate test below.
     */
    #[Test]
    public function a_conversation_already_answered_refuses_the_next_operator_by_compare_and_set(): void
    {
        $this->fakeApi();

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        $second = User::factory()->create();
        $second->projects()->attach($this->project, ['role' => 'member']);

        $this->send($conversation, self::DRAFT)->assertSessionHasNoErrors();

        $refused = $this->actingAs($second)
            ->from(route('engage.index'))
            ->post(route('engage.send', $conversation), ['text' => self::DRAFT]);

        $refused->assertSessionHasErrors('reply');

        $errors = session('errors');

        $this->assertNotNull($errors);
        $this->assertStringContainsString(
            'already been answered',
            (string) $errors->first('reply'),
        );

        // One reply in the thread, which is the entire point.
        $this->assertCount(1, $this->calls('/threads'));
        $this->assertCount(1, $this->calls('/threads_publish'));
        $this->assertSame('post-1', $conversation->refresh()->reply_external_id);
    }

    /**
     * The row is actually locked, and the lock is what a second operator waits
     * on — the claim the test above cannot make.
     *
     * Two operators on one queue is the normal case on the phone §7 designs
     * for. Compare-and-set refuses the second *write*, and by then the second
     * reply is on the platform; the lock is what makes the second request
     * re-read before it calls anything. PHPUnit is one process, so the way to
     * assert a lock without a second connection is to read the SQL: `select …
     * for update` on `interactions` before the first request to Threads.
     */
    #[Test]
    public function the_send_path_takes_a_row_lock_before_it_decides_anything(): void
    {
        $this->fakeApi();

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        /** @var list<string> $statements */
        $statements = [];

        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->send($conversation, self::DRAFT)->assertSessionHasNoErrors();

        $locking = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'from "interactions"')
                && str_contains($sql, 'for update'),
        ));

        $this->assertNotEmpty(
            $locking,
            'The send path must re-read the conversation under `lockForUpdate()` (§4.2, §7).',
        );
    }

    /**
     * §7's other half of the same problem: the lock must not be held across the
     * network.
     *
     * One transaction around the whole send held `lockForUpdate()` through a
     * quota read, a possible token renewal, a container and a publish — four
     * requests at a thirty-second timeout, so the second operator's phone hung
     * for up to two minutes before being told somebody got there first. The
     * claim is a transaction, the calls are not.
     *
     * `RefreshDatabase` wraps every test in one transaction of its own, so
     * level 1 during a call means "nothing of ours is open".
     */
    #[Test]
    public function no_http_call_happens_while_the_row_is_locked(): void
    {
        $conversation = $this->arrive();
        $this->ourPost('root-1');

        /** @var list<int> $levels */
        $levels = [];

        Http::fake(function (Request $request) use (&$levels) {
            $levels[] = DB::transactionLevel();

            return str_contains($request->url(), 'threads_publishing_limit')
                ? Http::response([
                    'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]],
                ])
                : Http::response(['id' => 'post-1']);
        });

        $this->send($conversation, self::DRAFT)->assertSessionHasNoErrors();

        $this->assertNotEmpty($levels);
        $this->assertSame(
            [1],
            array_values(array_unique($levels)),
            'Every Threads call must happen outside the transaction holding the row lock (§7).',
        );
    }

    // ----------------------------------------------------------- §7 the screen

    #[Test]
    public function the_queue_renders_for_a_project_member_scoped_to_the_project_and_oldest_first(): void
    {
        // Three, and every position asserted. Two rows and a first-row
        // assertion is a coin toss: deleting `orderBy('received_at')` from
        // scopeOpen() left that green, because Postgres happened to hand back
        // the rows in the order that made it true.
        $newest = Interaction::factory()->create([
            'channel_id' => $this->channel->getKey(),
            'author_handle' => '@newest',
            'received_at' => now()->subMinutes(3),
        ]);

        $oldest = Interaction::factory()->create([
            'channel_id' => $this->channel->getKey(),
            'author_handle' => '@oldest',
            'received_at' => now()->subHours(6),
        ]);

        $middle = Interaction::factory()->create([
            'channel_id' => $this->channel->getKey(),
            'author_handle' => '@middle',
            'received_at' => now()->subHours(2),
        ]);

        // Answered and skipped conversations are gone: §3 calls this a duty
        // queue rather than a log.
        Interaction::factory()->inState(InteractionState::Answered)->create([
            'channel_id' => $this->channel->getKey(),
        ]);

        // Another tenant's conversation, which must not appear at any price.
        $other = Project::factory()->create();
        app(CurrentProject::class)->run($other, function (): void {
            Interaction::factory()->create([
                'channel_id' => Channel::factory()->threads()->create()->getKey(),
                'author_handle' => '@somebody-elses-project',
            ]);
        });

        /** @var list<string> $statements */
        $statements = [];

        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->screen()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('engage/index')
            ->has('conversations.data', 3)
            // Oldest first, which is the opposite of every other list in the
            // engine and is the whole point: waiting is what §4.2 is judged on.
            ->where('conversations.data.0.id', $oldest->getKey())
            ->where('conversations.data.0.handle', '@oldest')
            ->where('conversations.data.0.waited_seconds', 6 * 3600)
            ->where('conversations.data.1.id', $middle->getKey())
            ->where('conversations.data.2.id', $newest->getKey())
            ->where('answered_today', 1)
            ->has('reasons', count(InteractionSkipReason::cases()))
        );

        // And the order is asked for rather than inherited. Deleting
        // `orderBy('received_at')` from `scopeOpen()` leaves every assertion
        // above green: `interactions` is indexed on
        // `(project_id, state, received_at)`, so Postgres walks it in exactly
        // the order the test wanted and answers a question nobody asked. The
        // day the planner picks a sequential scan instead, the duty queue
        // quietly stops being a queue — so the `order by` is the assertion.
        $ordered = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'from "interactions"')
                && str_contains($sql, 'order by "received_at" asc'),
        ));

        $this->assertNotEmpty(
            $ordered,
            'The duty queue must order by received_at rather than trusting the planner (§4.2).',
        );
    }

    // -------------------------------------------------------------- §11.1

    #[Test]
    public function with_foreign_replies_off_a_conversation_is_prepared_and_recorded_by_hand(): void
    {
        $this->fakeApi();

        config()->set('social.threads.reply_to_foreign', false);

        // A conversation on somebody else's post: nothing in the delivery
        // journal claims this root.
        $conversation = $this->arrive('reply-9', ['root_post' => ['id' => 'not-ours']]);

        $this->assertSame(self::DRAFT, $conversation->draft_reply);

        // The screen says so, and gives the operator a working path rather
        // than an error: the text, the thread, and a way to record it.
        $this->screen()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('foreign_replies_allowed', false)
            ->where('conversations.data.0.route', 'by_hand')
            ->where('conversations.data.0.route_label', 'Copy and post')
            ->where('conversations.data.0.draft', self::DRAFT)
            ->where('conversations.data.0.sendable', true)
            ->where('conversations.data.0.permalink', 'https://www.threads.net/@asker/post/reply-9')
        );

        // The engine does not send it, even when asked directly.
        $this->send($conversation, self::DRAFT)->assertSessionHasErrors('reply');

        $this->assertSame([], $this->calls('/threads'));
        $this->assertSame(InteractionState::Drafted, $conversation->refresh()->state);

        // The operator posts it and says so. §4.2's contour survives as
        // human-assisting, and the latency is measured exactly the same way.
        Carbon::setTestNow('2026-08-09 12:06:00');

        // Not the draft. The operator had the box open in front of them and
        // changed a word, and this is the assertion that says the row records
        // what they posted: with the same text on both sides it was a tautology
        // against a factory default, and `recordSentByHand()` could keep the
        // engine's draft and stay green — on the *default* §11.1 path.
        $posted = 'Vinegar is fine on the glass — keep it off the marble sill.';

        $this->record($conversation, $posted, [
            'reference' => 'https://www.threads.net/@brand/post/BBB',
        ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $answered = $conversation->refresh();

        $this->assertSame(InteractionState::Answered, $answered->state);
        $this->assertSame($posted, $answered->draft_reply);
        $this->assertSame('https://www.threads.net/@brand/post/BBB', $answered->reply_external_id);
        $this->assertNotNull($answered->answered_at);
        $this->assertSame(0, Interaction::query()->open()->count());

        // Still nothing to the platform: we recorded a fact, we did not make a
        // call and we did not invent an id for one.
        $this->assertSame([], $this->calls('/threads'));
        $this->assertSame([], $this->calls('/threads_publish'));
    }

    #[Test]
    public function with_foreign_replies_off_a_reply_recorded_without_a_link_says_so(): void
    {
        Http::fake();

        $conversation = $this->arrive('reply-9', ['root_post' => ['id' => 'not-ours']]);

        $this->actingAs($this->operator)
            ->from(route('engage.index'))
            ->post(route('engage.sent', $conversation), ['text' => self::DRAFT])
            ->assertSessionHasNoErrors();

        // Hunting for a permalink on a phone is the friction §7 removes, and a
        // row that says "posted by hand" is honest where a fabricated platform
        // id would look like evidence of an API send.
        $this->assertSame(InteractionReplySender::BY_HAND, $conversation->refresh()->reply_external_id);
    }

    #[Test]
    public function with_foreign_replies_on_the_engine_may_send_to_a_foreign_post(): void
    {
        $this->fakeApi();

        // The day somebody checks this against a live account, §11.1 becomes a
        // config change and not a rewrite. This is that config change.
        config()->set('social.threads.reply_to_foreign', true);

        $conversation = $this->arrive('reply-9', ['root_post' => ['id' => 'not-ours']]);

        $this->screen()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('foreign_replies_allowed', true)
            ->where('conversations.data.0.route', 'api')
        );

        $this->send($conversation, self::DRAFT)->assertSessionHasNoErrors();

        $this->assertSame(InteractionState::Answered, $conversation->refresh()->state);
        $this->assertSame('post-1', $conversation->refresh()->reply_external_id);
        $this->assertSame('reply-9', $this->calls('/threads')[0]['reply_to_id']);
    }

    /**
     * The default §11.1 path, with every reason to refuse stacked on it.
     *
     * `recordSentByHand()` used to run the same gate as `send()` — the guard
     * and the carried fact-check — and this is what that meant on the path
     * every foreign-post conversation takes: the operator copies the draft,
     * posts it in the thread, comes back, presses "Mark as sent", and is
     * refused because of the text they have already published. The reply is
     * live, the queue says unanswered, the row never leaves `scopeOpen()` and
     * §4.2's latency for it has no upper bound.
     *
     * A record of something that happened cannot be refused for being unwise.
     * It is written down instead — findings and all.
     */
    #[Test]
    public function a_reply_the_operator_already_posted_is_recorded_rather_than_refused(): void
    {
        Http::fake();

        $this->project->forceFill(['is_ymyl' => true])->save();
        BrandBrief::factory()->create(['forbidden_topics' => ['guaranteed returns']]);

        $this->models->willAnswerRole('factcheck', 'The reply claims a 0.2% fee, which no fact supports.');

        $conversation = $this->arrive('reply-9', ['root_post' => ['id' => 'not-ours']]);

        // Everything the send path would refuse: a forbidden topic, a failed
        // fact-check on a YMYL project, and no acknowledgement of either.
        $posted = 'We cannot promise guaranteed returns, but our fee is 1.4% per trade.';

        $this->send($conversation, $posted)->assertSessionHasErrors('reply');

        $this->record($conversation, $posted)->assertSessionHasNoErrors();

        $answered = $conversation->refresh();

        $this->assertSame(InteractionState::Answered, $answered->state);
        $this->assertSame($posted, $answered->draft_reply);
        $this->assertSame(InteractionReplySender::BY_HAND, $answered->reply_external_id);
        $this->assertSame(0, Interaction::query()->open()->count());

        // Said, not swallowed. §7 wants the engine to explain what it did not
        // like; refusing is a different thing from explaining.
        $attempt = InteractionReplyAttempt::query()->sole();

        $this->assertSame(ReplyAttemptOutcome::RecordedByHand, $attempt->outcome);
        $this->assertSame($posted, $attempt->text);
        $this->assertSame($this->operator->getKey(), $attempt->user_id);

        $codes = array_column((array) ($attempt->findings['findings'] ?? []), 'code');

        $this->assertContains('forbidden_topic', $codes);
        $this->assertContains('factcheck', $codes);
    }

    #[Test]
    public function a_reply_longer_than_the_platform_accepts_cannot_have_been_posted(): void
    {
        Http::fake();

        $conversation = $this->arrive('reply-9', ['root_post' => ['id' => 'not-ours']]);

        // The one judgement kept on this path, and it is a fact about Threads
        // rather than a policy: the platform refuses it, so it cannot be what
        // is in the thread.
        $this->record($conversation, str_repeat('я', 501))->assertSessionHasErrors();

        $this->assertSame(InteractionState::Drafted, $conversation->refresh()->state);

        // And so is an empty one — there is nothing to record.
        $this->record($conversation, '   ')->assertSessionHasErrors();

        $this->assertSame(InteractionState::Drafted, $conversation->refresh()->state);
    }

    // ------------------------------------------------------------------- §9

    /**
     * The publish request left and nothing came back — §9's asymmetry.
     *
     * The warning used to live in one flash message on one screen. The operator
     * reloads and it is gone; a second operator never saw it; and everything
     * the transaction did was rolled back, so the row rendered as an ordinary
     * drafted conversation with a green Send button over a thread that may
     * already have our reply in it. The sender's own argument for not retrying
     * on the operator's behalf rested on the operator's memory.
     */
    #[Test]
    public function a_publish_that_never_answered_leaves_a_warning_on_the_row(): void
    {
        Http::fake([
            'graph.threads.net/v1.0/*/threads_publishing_limit*' => Http::response([
                'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]],
            ]),
            'graph.threads.net/v1.0/*/threads_publish' => static fn (): never => throw new ConnectionException('timed out'),
            'graph.threads.net/v1.0/*/threads' => Http::response(['id' => 'container-1']),
        ]);

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        $refused = $this->send($conversation, self::DRAFT);

        $refused->assertSessionHasErrors('reply');

        // The record survives the rollback, because it was never inside it.
        $attempt = InteractionReplyAttempt::query()->sole();

        $this->assertSame(ReplyAttemptOutcome::Unconfirmed, $attempt->outcome);
        $this->assertSame(self::DRAFT, $attempt->text);

        // And it is on the row, for whoever opens the queue next — including
        // the operator who reloaded and lost the flash message.
        $this->screen()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversations.data.0.sendable', false)
            ->where('conversations.data.0.findings.0.code', 'unconfirmed_send')
            ->where('conversations.data.0.findings.0.blocking', true)
            ->where('conversations.data.0.findings.0.acknowledgeable', true)
        );

        // A second Send is refused rather than putting a second reply into a
        // thread nobody has looked at.
        $this->fakeApi();
        $this->send($conversation, self::DRAFT)->assertSessionHasErrors('reply');

        $this->assertSame([], $this->calls('/threads_publish'));

        // The two ways out, and both are a person deciding. Recording it by
        // hand closes the row on the reply that may already be there…
        $this->record($conversation, self::DRAFT)->assertSessionHasNoErrors();

        $this->assertSame(InteractionState::Answered, $conversation->refresh()->state);
    }

    /**
     * …and saying "I looked, it is not there" is the other, which is why the
     * warning is acknowledgeable rather than terminal.
     */
    #[Test]
    public function an_operator_who_checked_the_thread_may_send_again(): void
    {
        // The first publish never answers; the second one does.
        $publishes = 0;

        Http::fake(function (Request $request) use (&$publishes) {
            if (str_contains($request->url(), 'threads_publishing_limit')) {
                return Http::response([
                    'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]],
                ]);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                $publishes++;

                if ($publishes === 1) {
                    throw new ConnectionException('timed out');
                }

                return Http::response(['id' => 'post-1']);
            }

            return Http::response(['id' => 'container-1']);
        });

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        $this->send($conversation, self::DRAFT)->assertSessionHasErrors('reply');

        $this->send($conversation, self::DRAFT, ['unconfirmed_send'])->assertSessionHasNoErrors();

        $this->assertSame(InteractionState::Answered, $conversation->refresh()->state);
        $this->assertSame('post-1', $conversation->refresh()->reply_external_id);

        // Two attempts on the record, and they say different things.
        $this->assertSame(
            [ReplyAttemptOutcome::Unconfirmed, ReplyAttemptOutcome::Delivered],
            InteractionReplyAttempt::query()->orderBy('created_at')->orderBy('id')->pluck('outcome')->all(),
        );
    }

    /**
     * The publish succeeded and the row could not be closed on it.
     *
     * The second of the two paths that used to leave no trace: `markAnswered()`
     * hits `ConcurrentStateChange` *after* a successful publish, the transaction
     * rolls back, and the reply is live with nothing anywhere saying so. The
     * publish now happens outside the transaction, which is what makes this
     * drivable: the conversation is moved underneath the send from inside the
     * HTTP fake, exactly as a second operator would move it.
     */
    #[Test]
    public function a_reply_that_reached_the_platform_is_recorded_even_when_the_row_cannot_be_closed(): void
    {
        $conversation = $this->arrive();
        $this->ourPost('root-1');

        Http::fake(function (Request $request) use ($conversation) {
            if (str_contains($request->url(), 'threads_publishing_limit')) {
                return Http::response([
                    'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]],
                ]);
            }

            if (str_contains($request->url(), '/threads_publish')) {
                // Somebody else answered it while our publish was in the air.
                Interaction::query()->whereKey($conversation->getKey())->update([
                    'state' => InteractionState::Answered->value,
                    'answered_at' => now(),
                    'reply_external_id' => 'somebody-elses-reply',
                ]);
            }

            return Http::response(['id' => 'post-1']);
        });

        $this->send($conversation, self::DRAFT)->assertSessionHasErrors('reply');

        $attempt = InteractionReplyAttempt::query()->sole();

        // The platform's id is on the record even though the row was never
        // closed on it — which is the difference between "we do not know" and
        // "we know, and we know what we sent".
        $this->assertSame(ReplyAttemptOutcome::Unconfirmed, $attempt->outcome);
        $this->assertSame('post-1', $attempt->reply_external_id);
        $this->assertNotNull($attempt->detail);
    }

    // ------------------------------------------------------------- §2 budget

    #[Test]
    public function a_reply_spends_two_actions_and_is_refused_when_the_window_is_full(): void
    {
        // A container and a publish, out of the 250 of §2.
        $this->fakeApi();

        $conversation = $this->arrive();
        $this->ourPost('root-1');

        $limiter = app(ThreadsRateLimiter::class);

        $this->assertSame(250, $limiter->remaining($this->channel, self::USER_ID, 'threads-token'));

        $this->send($conversation, self::DRAFT)->assertSessionHasNoErrors();

        $this->assertSame(248, $limiter->remaining($this->channel, self::USER_ID, 'threads-token'));

        // And with the window full, the operator is told now rather than
        // queued for a window that frees tomorrow: §4.2's value is in the first
        // hour, and posting it by hand spends nothing from this budget.
        $second = $this->arrive('reply-2');

        $limiter->spend($this->channel, 249);

        $refused = $this->send($second, self::DRAFT);

        $refused->assertSessionHasErrors('reply');

        $errors = session('errors');

        $this->assertNotNull($errors);
        $this->assertStringContainsString('publishing budget', (string) $errors->first('reply'));

        // Refused before anything was claimed, so there is no attempt to
        // explain away and nothing on the row to acknowledge.
        $this->assertSame(1, InteractionReplyAttempt::query()->count());
        $this->assertSame(InteractionState::Drafted, $second->refresh()->state);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * One event, all the way through: signed delivery → row → draft.
     *
     * The whole automated half of the contour in one call, run for real rather
     * than by starting the pipeline by hand — the thing under test is that
     * nothing between the webhook and the operator sends anything.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function arrive(string $id = 'reply-1', array $overrides = []): Interaction
    {
        $value = [
            'id' => $id,
            'text' => 'Does vinegar actually work on this?',
            'username' => 'asker',
            'permalink' => "https://www.threads.net/@asker/post/{$id}",
            'timestamp' => '2026-08-09T12:00:00+0000',
            'replied_to' => ['id' => 'parent-1'],
            'root_post' => ['id' => 'root-1'],
            ...$overrides,
        ];

        $payload = [
            'object' => 'user',
            'entry' => [[
                'id' => self::USER_ID,
                'time' => 1_786_000_000,
                'changes' => [['field' => 'replies', 'value' => $value]],
            ]],
        ];

        $body = (string) json_encode($payload);

        $this->call('POST', self::WEBHOOK, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertOk();

        return Interaction::query()->where('external_id', $id)->sole();
    }

    /**
     * A post of ours in the delivery journal, so §11.1's flag is irrelevant.
     *
     * The evidence {@see ReplyRouting} reads is `published_root_id`
     * in `channel_payload` — written by the publisher after a real publish, so
     * a root found there is a root we put there.
     */
    private function ourPost(string $rootId): ContentItem
    {
        return ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'state' => ContentItemState::Published,
            'channel_type' => ChannelType::Threads->value,
            'channel_payload' => [
                'segments' => [['text' => 'Salt eats glass.', 'published_id' => $rootId]],
                'published_root_id' => $rootId,
            ],
        ]);
    }

    /**
     * The operator pressing Send, with whatever is in the box and whatever
     * they ticked.
     *
     * @param  list<string>  $acknowledged
     * @return TestResponse<Response>
     */
    private function send(Interaction $conversation, string $text, array $acknowledged = []): TestResponse
    {
        return $this->actingAs($this->operator)
            ->from(route('engage.index'))
            ->post(route('engage.send', $conversation), [
                'text' => $text,
                'acknowledged' => $acknowledged,
            ]);
    }

    /**
     * The operator saying they posted it themselves (§11.1).
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function record(Interaction $conversation, string $text, array $body = []): TestResponse
    {
        return $this->actingAs($this->operator)
            ->from(route('engage.index'))
            ->post(route('engage.sent', $conversation), ['text' => $text, ...$body]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function screen(): TestResponse
    {
        return $this->actingAs($this->operator)->get(route('engage.index'))->assertOk();
    }

    /**
     * The three addresses a reply touches, in match order.
     *
     * `Http::fake()` merges stubs rather than replacing them, so this may be
     * called after an earlier fake to add the publishing answers.
     */
    private function fakeApi(): void
    {
        Http::fake([
            'graph.threads.net/v1.0/*/threads_publishing_limit*' => Http::response([
                'data' => [['quota_usage' => 0, 'config' => ['quota_total' => 250, 'quota_duration' => 86_400]]],
            ]),
            'graph.threads.net/v1.0/*/threads_publish' => Http::response(['id' => 'post-1']),
            'graph.threads.net/v1.0/*/threads' => Http::response(['id' => 'container-1']),
            'graph.threads.net/v1.0/*' => Http::response(['id' => 'post-1']),
        ]);
    }

    /**
     * The bodies sent to one endpoint, in order.
     *
     * By endpoint rather than in total, because the adapter also reads the
     * budget and a bare count would make every assertion about the wrong call.
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
     * Every file under `$directory` whose text contains `$needle`, at all.
     *
     * Deliberately blunt — a docblock mentioning the sender from inside the
     * pipeline package would also fail, and it should: §4.2's guarantee is that
     * those packages have no reason to know the class exists.
     *
     * @return list<string>
     */
    private function filesMentioning(string $needle, string $directory): array
    {
        $found = [];

        foreach ($this->phpFilesIn(base_path($directory)) as $relative => $source) {
            if (str_contains($source, $needle)) {
                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Every file in the project that *uses* `$needle` rather than describing it.
     *
     * Docblock lines and bare `use` imports are excluded, because both exist to
     * make `{@see}` resolve — {@see ReplyRefused} imports
     * the sender for exactly that reason and calls nothing. What is left is a
     * type hint, a constructor argument or a static call, which is what "the
     * only caller" means.
     *
     * @return list<string>
     */
    private function filesUsing(string $needle): array
    {
        $found = [];

        foreach ($this->phpFilesInProject() as $relative => $source) {
            foreach (preg_split('/\R/', $source) ?: [] as $line) {
                $trimmed = ltrim($line);

                if (! str_contains($line, $needle)
                    || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*')
                    || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, 'use ')) {
                    continue;
                }

                $found[$relative] = true;

                break;
            }
        }

        $files = array_keys($found);
        sort($files);

        return $files;
    }

    /**
     * Everything this project's own PHP consists of.
     *
     * A denylist rather than an allowlist of directories, and that is the whole
     * correction: an allowlist only ever contains the places somebody thought
     * of, and the place §11.3 names — `routes/console.php`, the scheduler —
     * was not one of them. What is excluded is what this project did not write
     * (`vendor`, `node_modules`), what it generates (`storage`,
     * `bootstrap/cache`, `public/build`) and this suite itself, which names the
     * class it is asserting about.
     *
     * @return array<string, string> relative path => source
     */
    private function phpFilesInProject(): array
    {
        return $this->phpFilesIn(base_path(), [
            '.git',
            '.phpunit.cache',
            'bootstrap/cache',
            'node_modules',
            'public/build',
            'public/hot',
            'storage',
            'tests',
            'vendor',
        ]);
    }

    /**
     * @param  list<string>  $skip  path prefixes, relative to the project root
     * @return array<string, string> relative path => source
     */
    private function phpFilesIn(string $directory, array $skip = []): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $sources = [];
        $root = base_path().DIRECTORY_SEPARATOR;

        $tree = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);

        $pruned = new RecursiveCallbackFilterIterator(
            $tree,
            static function (SplFileInfo $file) use ($root, $skip): bool {
                $relative = str_replace($root, '', (string) $file->getPathname());

                foreach ($skip as $prefix) {
                    if ($relative === $prefix || str_starts_with($relative, $prefix.DIRECTORY_SEPARATOR)) {
                        return false;
                    }
                }

                return true;
            },
        );

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($pruned) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $file->getRealPath();

            $sources[str_replace($root, '', $path)] = (string) file_get_contents($path);
        }

        return $sources;
    }
}
