<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\InteractionState;
use App\Enums\ReplyAttemptOutcome;
use App\Enums\ReplyRoute;
use App\Enums\SocialBand;
use App\Http\Controllers\InteractionController;
use App\Integrations\Exceptions\ThreadsCredentialRejected;
use App\Integrations\Exceptions\ThreadsRefused;
use App\Integrations\Exceptions\ThreadsUnavailable;
use App\Integrations\Threads\ThreadsClient;
use App\Integrations\Threads\ThreadsConnection;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\Interaction;
use App\Models\InteractionReplyAttempt;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\User;
use App\Publishing\ThreadsPublisher;
use App\Publishing\ThreadsRateLimiter;
use App\Social\Exceptions\ReplyRefused;
use App\Support\Content\ConcurrentStateChange;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The one place a reply leaves the building — and it needs a person to do it.
 *
 * # §4.2's prohibition, as code rather than as a setting
 *
 * > **Автопаблиш здесь запрещён навсегда**, не «пока не заслужит»: ответ живому
 * > человеку от имени бренда без человека в контуре — это не экономия рутины,
 * > это способ однажды очень плохо пошутить.
 *
 * A flag that defaults to off is not that. A flag is a thing somebody turns on
 * at 2am to clear a backlog. So the prohibition here is four structural facts,
 * none of which is a configuration value:
 *
 * 1. **A human has to be authenticated, and it has to be the one being
 *    claimed.** {@see assertAHumanIsPressingTheButton()} refuses in console and
 *    refuses when `Auth::user()` is not `$operator`. The {@see User} parameter
 *    on its own was documentation: `User::first()` is one line from anywhere,
 *    and a scheduled task holding a user object is exactly the shape §4.2
 *    forbids. Now the parameter is a claim the runtime checks, so that task
 *    fails when it runs rather than at code review.
 * 2. **Its only caller is {@see InteractionController}**, behind the `auth`
 *    middleware. `EngageTest` asserts that by reading the whole project — not
 *    an allowlist of four directories under `app/`, because `routes/console.php`
 *    is where a scheduler lives and is not under `app/` at all.
 * 3. **The state machine has no single edge to `answered`.**
 *    {@see InteractionState::New} can only become `drafted` or `ignored`, and
 *    `answered` is reachable only from `drafted`. This does not stop
 *    automation — `markDrafted()` then `markAnswered()` is two public calls and
 *    {@see adopt()} below makes exactly that walk. What it stops is a lost
 *    answer under a race: `transitionTo()` is compare-and-set on the previous
 *    state, so a drafting job that comes back from a twenty-second model call
 *    cannot write `drafted` over a conversation an operator answered meanwhile.
 * 4. **The band refuses it.** {@see SocialBand::Conversation} answers false to
 *    `canEverAutopublish()`, and {@see assertBandForbidsAutomation()} below
 *    reads that answer on every send. It can never fire in the current code —
 *    which is the point: it is there so that a future edit turning the band
 *    permissive takes the reply path down with it instead of quietly enabling
 *    what §4.2 forbids.
 *
 * # Transport, and what the transaction is allowed to hold
 *
 * The same three collaborators {@see ThreadsPublisher} uses — the client, the
 * connection and the rate limiter — rather than a fourth place that knows how
 * to talk to Threads. §9's rule holds here too: what changes between a post and
 * a reply is one parameter, not the mechanics. The budget of §2 is counted in
 * actions, and a reply is two of them: a container and a publish.
 *
 * **No HTTP happens inside the row lock.** A send is three phases — claim,
 * call, close — and only the first and last are transactions. The alternative
 * was one transaction around everything, which held `lockForUpdate()` across a
 * quota read, a possible token renewal, a container and a publish: four
 * requests at a thirty-second timeout, so a second operator on the same
 * conversation waited up to two minutes on a phone to be told somebody got
 * there first. What the lock protects is the decision, and the decision is made
 * before the first call.
 *
 * **Every attempt is written down before it is made, in a row that survives its
 * own failure.** {@see InteractionReplyAttempt}, and it is the answer to the
 * one thing §9 says this contour cannot ask the platform: there is no
 * idempotency key, so a publish that leaves and never answers may or may not be
 * in the thread. Rolling back and flashing a sentence at whoever pressed the
 * button is not a record — they reload, it is gone, and the next operator sees
 * an ordinary drafted conversation with a green Send button over a thread that
 * may already have our reply in it. So the attempt row is opened outside the
 * closing transaction, closed as `unconfirmed` when the answer never came, and
 * read back by {@see ReplyClearance} as a blocking warning on the row. Retrying
 * on the operator's behalf is still the one thing that never happens.
 *
 * # §11.1
 *
 * Which conversations may be answered through the API at all is
 * {@see ReplyRouting}'s decision, and it is asked here as well as on the screen:
 * a route resolved when the page rendered is a route that could have changed by
 * the time the button was pressed.
 *
 * ⚠️ Unverified against a live account, like the rest of the Threads adapter.
 */
final class InteractionReplySender
{
    /** What `reply_external_id` says when the operator gave us nothing to record. */
    public const string BY_HAND = 'posted-by-hand';

    /** A container and a publish, from the 250 of §2. */
    private const int ACTIONS_PER_REPLY = 2;

    public function __construct(
        private readonly ThreadsClient $client,
        private readonly ThreadsConnection $connection,
        private readonly ThreadsRateLimiter $limiter,
        private readonly ReplyGuard $guard,
        private readonly ReplyRouting $routing,
        private readonly ReplyClearance $clearance,
    ) {}

    /**
     * Put `$text` into the thread as the brand, on `$operator`'s say-so.
     *
     * `$acknowledged` carries the codes the operator ticked — §10's fact-check
     * on a YMYL project, §9's unconfirmed previous send. See
     * {@see ReplyClearance} for why a tap and not a diff.
     *
     * @param  list<string>  $acknowledged
     *
     * @throws ReplyRefused when the reply did not go out, with what to tell the operator
     */
    public function send(
        Interaction $interaction,
        Project $project,
        User $operator,
        string $text,
        array $acknowledged = [],
    ): Interaction {
        $this->assertBandForbidsAutomation();
        $this->assertAHumanIsPressingTheButton($operator);

        $acknowledged = $this->clearance->sift($acknowledged);

        // Everything the lock has no opinion about, before the lock. The
        // connection, a token that may need renewing and the budget read are
        // facts about the account, not about this row, and a second operator
        // must not queue behind them.
        $channel = $this->channelOf($interaction);
        [$integration, $token, $userId] = $this->credentials();
        $this->assertBudget($channel, $userId, $token);

        [$locked, $attempt] = $this->claim($interaction, $project, $operator, $text, $acknowledged);

        try {
            $replyId = $this->post($locked, $text, $integration, $channel, $userId, $token);
        } catch (ReplyRefused $e) {
            // 409 is the sender's own word for "the platform may have taken it"
            // and 422 for "it provably did not" — see {@see post()}. The
            // distinction is the whole value of this row.
            $this->close(
                $attempt,
                $e->status === 409 ? ReplyAttemptOutcome::Unconfirmed : ReplyAttemptOutcome::Abandoned,
                detail: $e->getMessage(),
            );

            throw $e;
        }

        try {
            $answered = DB::transaction(function () use ($interaction, $text, $replyId): Interaction {
                $fresh = $this->lock($interaction);

                // What went out, not what was proposed. §7 gives the operator
                // an Edit box, and a row that kept the engine's wording would
                // make the thread and the queue disagree about what the brand
                // said.
                $fresh->draft_reply = $text;
                $fresh->markAnswered($replyId);

                return $fresh;
            });
        } catch (Throwable $e) {
            // The reply is on the platform and the row could not be closed on
            // it. Everything that transaction did is gone; this write is not
            // part of it, which is the entire reason the attempt row exists.
            $this->close($attempt, ReplyAttemptOutcome::Unconfirmed, $replyId, $e->getMessage());

            Log::error('A Threads reply was sent for a conversation that could not be closed on it', [
                'interaction' => $interaction->getKey(),
                'reply' => $replyId,
                'operator' => $operator->getKey(),
                'reason' => $e->getMessage(),
            ]);

            throw ReplyRefused::conflict(
                'The reply was posted, but the conversation could not be closed on it — somebody else may have '
                .'moved it. It is now marked as possibly already answered; check the thread before sending again.'
            );
        }

        $this->close($attempt, ReplyAttemptOutcome::Delivered, $replyId);

        Log::info('An operator sent a Threads reply', [
            'interaction' => $answered->getKey(),
            'reply' => $replyId,
            'operator' => $operator->getKey(),
            // §4.2 is judged on this number.
            'waited_seconds' => (int) $answered->received_at->diffInSeconds($answered->answered_at),
        ]);

        return $answered;
    }

    /**
     * The operator posted it themselves; write down that the debt is paid.
     *
     * §11.1's human-assisting shape, and the reason it is a first-class action
     * rather than an apology: with `reply_to_foreign` off — the default, and the
     * honest position until somebody checks — a conversation on somebody else's
     * post is answered by a person opening the thread. The engine still found
     * it, still wrote the reply and still measures the latency; what it does not
     * do is the HTTP call.
     *
     * **It records a fact that has already happened, so almost nothing may
     * refuse it.** This used to run the same gate as {@see send()}, fact-check
     * and forbidden topics and all, on the *default* §11.1 path: flag off,
     * foreign post, operator copies the draft, posts it in the thread, comes
     * back, and is told the text they have already published is not allowed.
     * The reply is live, the queue says unanswered, the row never leaves
     * {@see Interaction::scopeOpen()} and §4.2's latency for it is unbounded.
     * What is left is the two facts about the platform — there is no text at
     * all, or it is longer than Threads accepts, so it cannot be what went into
     * the thread. Every judgement is kept as findings on the attempt record
     * instead of refusing: §7 wants the engine to say what it did not like, and
     * refusing is a different thing from saying so.
     *
     * `$reference` is whatever the operator can give us — the URL of the reply
     * they posted, usually. There is no platform id to record because we did not
     * make the call, and inventing one would make the row look like evidence of
     * an API send. {@see BY_HAND} is stored when they give nothing, which says
     * exactly what happened.
     *
     * @param  list<string>  $acknowledged
     *
     * @throws ReplyRefused
     */
    public function recordSentByHand(
        Interaction $interaction,
        Project $project,
        User $operator,
        string $text,
        ?string $reference,
        array $acknowledged = [],
    ): Interaction {
        $this->assertBandForbidsAutomation();
        $this->assertAHumanIsPressingTheButton($operator);

        $acknowledged = $this->clearance->sift($acknowledged);

        return DB::transaction(function () use ($interaction, $project, $operator, $text, $reference, $acknowledged): Interaction {
            $locked = $this->lock($interaction);

            $this->assertOpen($locked);

            $verdict = $this->verdict($locked, $project, $text);

            $this->assertRecordable($verdict, $text);

            // The words the operator says they used, not the draft: they had
            // the text box open in front of them and may well have changed it.
            $this->adopt($locked, $text);
            $locked->draft_reply = $text;

            try {
                $locked->markAnswered($this->reference($reference));
            } catch (ConcurrentStateChange $e) {
                throw ReplyRefused::conflict($e->getMessage());
            }

            // No HTTP happened, so nothing here has to survive a rollback: if
            // this transaction is undone, the reply was never recorded either.
            $this->attempt(
                interaction: $locked,
                operator: $operator,
                route: ReplyRoute::ByHand,
                text: $text,
                acknowledged: $acknowledged,
                verdict: $verdict,
                outcome: ReplyAttemptOutcome::RecordedByHand,
                replyId: $locked->reply_external_id,
            );

            Log::info('An operator recorded a Threads reply they posted by hand', [
                'interaction' => $locked->getKey(),
                'operator' => $operator->getKey(),
                'waited_seconds' => (int) $locked->received_at->diffInSeconds($locked->answered_at),
            ]);

            return $locked;
        });
    }

    /**
     * Phase one: decide, and write down that we are about to call.
     *
     * Short on purpose. Everything in here is a read of this row and a write to
     * it, which is what `lockForUpdate()` is for; nothing in here can block on
     * a network.
     *
     * @param  list<string>  $acknowledged
     * @return array{Interaction, InteractionReplyAttempt}
     *
     * @throws ReplyRefused
     */
    private function claim(
        Interaction $interaction,
        Project $project,
        User $operator,
        string $text,
        array $acknowledged,
    ): array {
        /** @var array{Interaction, InteractionReplyAttempt} $claimed */
        $claimed = DB::transaction(function () use ($interaction, $project, $operator, $text, $acknowledged): array {
            // The row lock, and it is the real concurrency answer rather than a
            // formality. `transitionTo()` is compare-and-set, which refuses the
            // *second write* — and by then the second reply is already on the
            // platform. Two operators pressing Send on one conversation is not
            // hypothetical: §7 designs this screen for a phone and a
            // notification, which is exactly how two people open the same
            // queue. So the second one waits here, re-reads below, and is
            // refused before a single call goes out.
            $locked = $this->lock($interaction);

            $this->assertOpen($locked);

            if ($this->routing->for($locked) !== ReplyRoute::Api) {
                throw ReplyRefused::refused(
                    'This conversation is on a post we did not publish, and replying to other people’s '
                    .'posts through the API is not something the platform documents. Copy the reply and '
                    .'post it yourself, then mark it sent.'
                );
            }

            $verdict = $this->verdict($locked, $project, $text);

            $refusal = $this->clearance->refusal($verdict, $acknowledged);

            if ($refusal !== null) {
                throw ReplyRefused::refused($refusal);
            }

            $this->adopt($locked, $text);

            return [$locked, $this->attempt(
                interaction: $locked,
                operator: $operator,
                route: ReplyRoute::Api,
                text: $text,
                acknowledged: $acknowledged,
                verdict: $verdict,
                outcome: ReplyAttemptOutcome::InFlight,
            )];
        });

        return $claimed;
    }

    /**
     * Everything that could stop this reply, over the text actually being sent.
     *
     * The guard's own findings (§4.3) plus the two that are not about the text
     * at all — §10's fact-check duty on a YMYL project and §9's unconfirmed
     * previous send. See {@see ReplyClearance}.
     */
    private function verdict(Interaction $interaction, Project $project, string $text): ReplyGuardVerdict
    {
        $verdict = $this->guard->check($text, $project, BrandBrief::activeFor($project));

        $contextual = $this->clearance->contextual(
            $project,
            ReplyGuardVerdict::fromArray($interaction->draft_guard),
            $this->clearance->hasUnconfirmedSend($interaction),
        );

        foreach ($contextual as $finding) {
            $verdict = $verdict->with($finding);
        }

        return $verdict;
    }

    /**
     * The two things that make a recorded reply impossible rather than unwise.
     *
     * Both are facts about Threads: an empty string is not a reply anybody
     * posted, and the platform refuses anything over its own limit, so text
     * longer than that cannot be what is in the thread. Everything else the
     * guard found is stored with the record rather than thrown at the operator
     * — see {@see recordSentByHand()}.
     *
     * @throws ReplyRefused
     */
    private function assertRecordable(ReplyGuardVerdict $verdict, string $text): void
    {
        if ($verdict->finding(ReplyGuard::BLANK) !== null) {
            throw ReplyRefused::refused(
                'There is nothing to record. Write down what you posted, so the queue and the thread agree.'
            );
        }

        if ($verdict->finding(ReplyGuard::LENGTH) !== null) {
            throw ReplyRefused::refused(
                'That is '.mb_strlen(trim($text)).' characters and Threads refuses anything over '
                .$this->guard->textLimit().', so it cannot be what went into the thread. '
                .'Record the reply you actually posted.'
            );
        }
    }

    /**
     * Create the container, publish it, and answer with the platform's id.
     *
     * `reply_to_id` is the id of *their* reply, not of the post they were
     * answering: we are talking to the person, and threading it anywhere else
     * would put our answer next to their question rather than under it.
     *
     * Called with the connection already resolved and the budget already
     * checked, and outside any transaction — see the class docblock.
     *
     * @throws ReplyRefused
     */
    private function post(
        Interaction $interaction,
        string $text,
        ProjectIntegration $integration,
        Channel $channel,
        string $userId,
        string $token,
    ): string {
        try {
            $created = $this->client->post("{$userId}/threads", [
                'media_type' => 'TEXT',
                'text' => $text,
                'reply_to_id' => $interaction->external_id,
            ], $token);
        } catch (ThreadsCredentialRejected $e) {
            $integration->markBroken("Threads rejected the connection: {$e->getMessage()} Reconnect to grant access again.");

            throw ReplyRefused::refused("Threads rejected the connection: {$e->getMessage()} Reconnect the account to reply.");
        } catch (ThreadsUnavailable $e) {
            // Nothing can be live: a container is not a post, and an orphan one
            // expires by itself. Safe to say "try again".
            throw ReplyRefused::refused("Threads did not take the reply: {$e->getMessage()} Nothing was posted; try again.");
        } catch (ThreadsRefused $e) {
            throw ReplyRefused::refused("Threads refused the reply: {$e->getMessage()}");
        }

        $containerId = $this->idIn($created);

        if ($containerId === null) {
            throw ReplyRefused::refused('Threads accepted the reply without answering with a container id. Nothing was posted; try again.');
        }

        $this->limiter->spend($channel, 1);

        try {
            $published = $this->client->post("{$userId}/threads_publish", ['creation_id' => $containerId], $token);
        } catch (ThreadsUnavailable $e) {
            if ($e->answered) {
                throw ReplyRefused::refused("Threads declined to publish the reply: {$e->getMessage()} Nothing was posted; try again.");
            }

            // §9's asymmetry, and the reason this is not retried for them: the
            // request left and nothing came back, so the reply may be in the
            // thread and there is no way to ask. The conversation stays open
            // and a person decides — with the warning on the row rather than in
            // a flash message only one operator ever sees.
            throw ReplyRefused::conflict(
                "Threads did not answer the publish: {$e->getMessage()} The reply may be live — check the "
                .'thread before sending it again, because the platform offers no way to ask.'
            );
        } catch (ThreadsCredentialRejected|ThreadsRefused $e) {
            throw ReplyRefused::refused("Threads refused to publish the reply: {$e->getMessage()}");
        }

        $this->limiter->spend($channel, 1);

        $replyId = $this->idIn($published);

        if ($replyId === null) {
            throw ReplyRefused::conflict(
                'Threads accepted the publish without answering with an id. The reply may be live — check '
                .'the thread before sending it again.'
            );
        }

        return $replyId;
    }

    /**
     * The account this project replies from, resolved before the lock.
     *
     * @return array{ProjectIntegration, string, string} integration, token, user id
     *
     * @throws ReplyRefused
     */
    private function credentials(): array
    {
        $integration = $this->connection->for();

        if ($integration === null) {
            throw ReplyRefused::refused('No Threads account is connected to this project, so there is nothing to reply from.');
        }

        $token = $this->connection->accessToken($integration);
        $userId = $this->connection->userId($integration);

        if ($token === null) {
            throw ReplyRefused::refused(trim(
                'Threads is no longer honouring this connection. Reconnect the account to reply. '
                .(string) $integration->failure_reason
            ));
        }

        if ($userId === null) {
            throw ReplyRefused::refused('The Threads connection does not know which account it is for. Reconnect it to reply.');
        }

        return [$integration, $token, $userId];
    }

    /**
     * §2's budget, read from the API (§9) and spent in actions.
     *
     * Not deferred to a queue, unlike a post. §4.2's value is in the first hour,
     * and a reply held for a window that frees tomorrow is not worth sending —
     * so the operator is told now and can post it by hand, which spends nothing
     * from this budget.
     *
     * @throws ReplyRefused
     */
    private function assertBudget(Channel $channel, string $userId, string $token): void
    {
        if ($this->limiter->allows($channel, self::ACTIONS_PER_REPLY, $userId, $token)) {
            return;
        }

        throw ReplyRefused::refused(
            'This account has spent its Threads publishing budget for the moment ('
            .$this->limiter->ceiling($channel, $userId, $token).' actions per window). '
            .'Post the reply yourself and mark it sent, or come back when the window frees.'
        );
    }

    /**
     * The channel the conversation arrived on, loaded rather than lazily read.
     *
     * The rate limiter is keyed on it (§9: "токен-бакет на канал") and the
     * application refuses a lazy load, so it is asked for explicitly.
     */
    private function channelOf(Interaction $interaction): Channel
    {
        $interaction->loadMissing('channel');

        /** @var Channel $channel */
        $channel = $interaction->getRelation('channel');

        return $channel;
    }

    /**
     * Open the record of an attempt, or write a settled one.
     *
     * @param  list<string>  $acknowledged
     */
    private function attempt(
        Interaction $interaction,
        User $operator,
        ReplyRoute $route,
        string $text,
        array $acknowledged,
        ReplyGuardVerdict $verdict,
        ReplyAttemptOutcome $outcome,
        ?string $replyId = null,
    ): InteractionReplyAttempt {
        return InteractionReplyAttempt::query()->create([
            'interaction_id' => $interaction->getKey(),
            'user_id' => $operator->getKey(),
            'route' => $route,
            'outcome' => $outcome,
            'text' => $text,
            'reply_external_id' => $replyId,
            'acknowledged' => $acknowledged,
            // What the guard made of the exact words that went out — including,
            // on the by-hand path, the judgements this engine may observe but
            // must not refuse over. §7 wants them said, not swallowed.
            'findings' => $verdict->toArray(),
        ]);
    }

    /** Settle an attempt. Never inside the transaction whose rollback it outlives. */
    private function close(
        InteractionReplyAttempt $attempt,
        ReplyAttemptOutcome $outcome,
        ?string $replyId = null,
        ?string $detail = null,
    ): void {
        $attempt->update([
            'outcome' => $outcome,
            'reply_external_id' => $replyId ?? $attempt->reply_external_id,
            'detail' => $detail,
        ]);
    }

    /**
     * Re-read the row while holding it.
     *
     * The instance the controller resolved was read before the operator's
     * request was even routed. Everything decided below is decided about the
     * row as it is now.
     */
    private function lock(Interaction $interaction): Interaction
    {
        /** @var Interaction $locked */
        $locked = Interaction::query()
            ->whereKey($interaction->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    /**
     * The conversation is still owed an answer.
     *
     * `new` counts, and it has to: a conversation whose drafting run failed —
     * or has not landed yet — is exactly the one an operator opens the screen
     * to deal with, and §7 would be a queue that shows you a problem and offers
     * no way to solve it. What arrives here for such a row is the operator's
     * own text; see {@see adopt()}.
     *
     * @throws ReplyRefused
     */
    private function assertOpen(Interaction $interaction): void
    {
        if (in_array($interaction->state, [InteractionState::New, InteractionState::Drafted], true)) {
            return;
        }

        throw ReplyRefused::conflict(match ($interaction->state) {
            InteractionState::Answered => 'This conversation has already been answered — somebody else got there first. '
                .'Reload the queue before answering it again.',
            default => 'This conversation was skipped by somebody else. Reload the queue.',
        });
    }

    /**
     * Make the operator's words the draft, when there was not one.
     *
     * `new → answered` is not an edge of {@see InteractionState}, deliberately:
     * a conversation cannot go from untouched to answered without a reply
     * existing somewhere. So a reply the operator wrote themselves becomes the
     * draft first and is then sent, which walks the machine as written rather
     * than adding a shortcut through it — and leaves the row saying what was
     * sent, which is what `draft_reply` is for.
     *
     * §10's fact-check has never seen these words and cannot: this is the path
     * where the drafting run failed. That is why the YMYL duty in
     * {@see ReplyClearance} is asked of the project rather than of the stored
     * verdict — otherwise this branch would be the hole in it.
     */
    private function adopt(Interaction $interaction, string $text): void
    {
        if ($interaction->state !== InteractionState::New) {
            return;
        }

        try {
            $interaction->markDrafted($text);
        } catch (ConcurrentStateChange $e) {
            throw ReplyRefused::conflict($e->getMessage());
        }
    }

    /**
     * The band's own answer, read on every send.
     *
     * Unreachable today by construction, and kept because "unreachable today"
     * is the property that decays. If somebody ever makes `Conversation`
     * autopublishable, this is what makes the reply path stop rather than
     * comply.
     */
    private function assertBandForbidsAutomation(): void
    {
        if (SocialBand::Conversation->canEverAutopublish()) {
            throw new LogicException(
                'SocialBand::Conversation reports that it may autopublish. §4.2 forbids that permanently — '
                .'"автопаблиш здесь запрещён навсегда, не «пока не заслужит»" — so the reply path refuses to '
                .'run at all rather than become the thing the spec rules out.'
            );
        }
    }

    /**
     * §4.2's human, as a runtime fact rather than a parameter.
     *
     * The {@see User} argument was described as "the seam automation cannot
     * cross" and was nothing of the kind: `User::first()` is one line, and four
     * lines in `routes/console.php` were enough to hand a scheduled task an
     * operator and send the whole open queue every minute. A type cannot
     * express "somebody is at the keyboard". Two questions can:
     *
     * - **Is this a console process?** A command, a worker and a scheduled task
     *   all are, and none of them has a person in it. Tests are exempted, and
     *   honestly so: the exemption is what lets the suite prove the console
     *   refusal exists, and the check below still applies to them.
     * - **Is the authenticated user the one being claimed?** This is the real
     *   gate. There is no authenticated user in a job, a command or a scheduled
     *   task, so the answer there is no whatever object was passed in.
     *
     * A {@see LogicException} rather than a {@see ReplyRefused}: this is not
     * something to tell an operator, it is a caller that must not exist.
     */
    private function assertAHumanIsPressingTheButton(User $operator): void
    {
        $app = app();

        if ($app->runningInConsole() && ! $app->runningUnitTests()) {
            throw new LogicException(
                'A reply cannot be sent from a console process. §4.2 forbids autopublish in this contour '
                .'permanently — "автопаблиш здесь запрещён навсегда" — and a command, a queue worker and a '
                .'scheduled task have no human in them, whichever User object they were handed.'
            );
        }

        $authenticated = Auth::user();

        if (! $authenticated instanceof User || $authenticated->getKey() !== $operator->getKey()) {
            throw new LogicException(
                'A reply can only be sent by the authenticated operator sending it. §4.2 requires a human in '
                .'this contour permanently — "ответ живому человеку от имени бренда без человека в контуре… '
                .'способ однажды очень плохо пошутить" — and a User this request did not authenticate as is '
                .'an object somebody looked up, not a person who read the reply.'
            );
        }
    }

    private function reference(?string $reference): string
    {
        $trimmed = trim((string) $reference);

        return $trimmed === '' ? self::BY_HAND : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function idIn(array $answer): ?string
    {
        $id = $answer['id'] ?? null;

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return is_int($id) ? (string) $id : null;
    }
}
