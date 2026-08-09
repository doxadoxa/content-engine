<?php

declare(strict_types=1);

namespace App\Social;

use App\Http\Controllers\InteractionController;
use App\Models\Interaction;
use App\Models\InteractionReplyAttempt;
use App\Models\Project;

/**
 * Everything standing between a drafted reply and the thread that is not about
 * the words themselves (§9, §10).
 *
 * {@see ReplyGuard} answers "is there anything wrong with this text". It is
 * deterministic, it reads only the text and the Brand Brief, and it is right to
 * be. Two things that also stop a reply are not properties of the text at all:
 *
 * 1. **§10's fact-check on a YMYL project.** "Для cryptoroute фактчек —
 *    обязательный шаг… Неверное число в посте расходится быстрее, чем в
 *    статье." Obligatory means obligatory for the reply that goes out, not for
 *    the draft the engine happened to write — so this is asked on every send on
 *    such a project, including the ones where the operator wrote every word
 *    themselves and the ones where the fact-check never ran.
 * 2. **§9's unconfirmed send.** A previous attempt whose publish request left
 *    and never answered may already be live in the thread, and the platform
 *    offers no way to ask.
 *
 * # Why an acknowledgement rather than a comparison
 *
 * The previous rule was `$project->is_ymyl && trim($text) === trim($draft)`:
 * the fact-check blocked while the words were still exactly the engine's, and
 * stopped blocking the moment they were not. It had three holes and they were
 * all the same hole. Appending a full stop cleared it while leaving the
 * unsupported number in the sentence. A conversation in `new` — the drafting
 * run failed, or has not landed — has no draft to compare against, so an
 * operator's own words on a YMYL project were never checked at all. And nothing
 * anywhere recorded that a human had looked.
 *
 * A diff is not consent. So the two findings here are **acknowledgeable**: the
 * operator is shown the sentence and taps once to say they have read it, the
 * tap rides on the send, and {@see InteractionReplyAttempt} records which codes
 * were acknowledged, by whom and when. That is one tap — §4.2's budget — an
 * audit trail §10 wants, and nothing whitespace can defeat.
 *
 * Findings that are *not* acknowledgeable stay unacknowledgeable, and the
 * difference is who can fix them. A reply over the platform's limit cannot be
 * posted by anybody; a topic the Brand Brief forbids is undone by editing the
 * words, which is the decision §4.2 put a human here to make. Neither is a
 * thing to tick past.
 *
 * @see InteractionController for how these reach the screen
 */
final class ReplyClearance
{
    /** A previous attempt may already be live in this thread (§9). */
    public const string UNCONFIRMED_SEND = 'unconfirmed_send';

    /**
     * The codes an operator may clear with one tap.
     *
     * Both are judgements a person makes by looking at something this engine
     * cannot see — the numbers in a sentence, and the thread itself.
     */
    public const array ACKNOWLEDGEABLE = [ReplyGuard::FACTCHECK, self::UNCONFIRMED_SEND];

    /**
     * The findings that do not come from the text, for one conversation.
     *
     * `$stored` is the verdict written when the draft was guarded. Only the
     * fact-check is carried out of it — it cost a model call and cannot be
     * redone inside a request somebody is waiting on — and it is carried as
     * *detail*, not as a verdict: what blocks on a YMYL project is the missing
     * acknowledgement, whether or not the fact-check found anything.
     *
     * @return list<ReplyGuardFinding>
     */
    public function contextual(Project $project, ReplyGuardVerdict $stored, bool $unconfirmed): array
    {
        $findings = [];
        $factcheck = $stored->finding(ReplyGuard::FACTCHECK);

        if ($project->is_ymyl) {
            $findings[] = new ReplyGuardFinding(
                ReplyGuard::FACTCHECK,
                $factcheck === null
                    ? 'This project is YMYL, so a fact-check is obligatory before a reply goes out. '
                        .'Confirm the claims and numbers in this reply are supported.'
                    : $factcheck->detail.' Confirm you have checked this before it goes out.',
                blocking: true,
            );
        } elseif ($factcheck !== null) {
            // Visible, never gating. Off a YMYL project §10 asks for the
            // finding to reach the operator, not for the send to stop.
            $findings[] = $factcheck->blocking(false);
        }

        if ($unconfirmed) {
            $findings[] = new ReplyGuardFinding(
                self::UNCONFIRMED_SEND,
                'A reply to this conversation was already sent to Threads and the platform never confirmed it, '
                .'so it may already be live. Open the thread and look before sending another one.',
                blocking: true,
            );
        }

        return $findings;
    }

    /**
     * The first reason this reply may not go out, given what the operator ticked.
     *
     * An acknowledgeable finding that was acknowledged stops blocking; anything
     * else that blocks, blocks.
     *
     * @param  list<string>  $acknowledged
     */
    public function refusal(ReplyGuardVerdict $verdict, array $acknowledged): ?string
    {
        foreach ($verdict->findings as $finding) {
            if (! $finding->blocking) {
                continue;
            }

            if ($this->acknowledgeable($finding->code) && in_array($finding->code, $acknowledged, true)) {
                continue;
            }

            return $finding->detail;
        }

        return null;
    }

    public function acknowledgeable(string $code): bool
    {
        return in_array($code, self::ACKNOWLEDGEABLE, true);
    }

    /**
     * Only the codes that mean something, out of whatever the request carried.
     *
     * An acknowledgement is a record of what a person said they had checked, so
     * a body full of invented codes must not be able to write invented history
     * onto {@see InteractionReplyAttempt}.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function sift(array $codes): array
    {
        return array_values(array_unique(array_filter(
            $codes,
            fn (string $code): bool => $this->acknowledgeable($code),
        )));
    }

    /** Whether this one conversation is carrying an attempt nobody could close. */
    public function hasUnconfirmedSend(Interaction $interaction): bool
    {
        return InteractionReplyAttempt::query()
            ->where('interaction_id', $interaction->getKey())
            ->openQuestion()
            ->exists();
    }

    /**
     * The same answer for a whole page of the duty queue, in one query.
     *
     * Twenty-five `exists()` calls is how §7's screen stops opening in seconds
     * on a phone — the same reasoning as {@see ReplyRouting::forMany()}.
     *
     * @param  iterable<Interaction>  $interactions
     * @return array<string, true> keyed by interaction id
     */
    public function unconfirmedSendsFor(iterable $interactions): array
    {
        $ids = [];

        foreach ($interactions as $interaction) {
            $ids[] = (string) $interaction->getKey();
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<string, true> $found */
        $found = InteractionReplyAttempt::query()
            ->whereIn('interaction_id', $ids)
            ->openQuestion()
            ->pluck('interaction_id')
            ->flip()
            ->map(static fn (): bool => true)
            ->all();

        return $found;
    }
}
