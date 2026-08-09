<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InteractionReplyAttempt;
use App\Social\InteractionReplySender;

/**
 * What became of one attempt to answer a conversation (§9).
 *
 * §9 states the fact this enum exists for: Threads has no idempotency key, so
 * "запрос ушёл и ничего не вернулось" is not the same as "ничего не
 * опубликовалось" and there is no call that can tell the two apart. The only
 * honest record of a reply that may or may not be live is one written *before*
 * the call and closed after it, and the closing value is this.
 *
 * Two of the four are settled — {@see Delivered} and {@see Abandoned} — and the
 * other two are the open ones. Anything that is not settled is shown on the
 * duty screen as a blocking warning, because a process that died between the
 * container and the publish leaves {@see InFlight} behind for ever and that is
 * exactly as unknown as {@see Unconfirmed}.
 *
 * @see InteractionReplyAttempt
 * @see InteractionReplySender
 */
enum ReplyAttemptOutcome: string
{
    /** Written before the publish call. Nothing has come back yet. */
    case InFlight = 'in_flight';

    /** The platform answered with an id, and the row was closed on it. */
    case Delivered = 'delivered';

    /**
     * Provably nothing reached the thread.
     *
     * The container was refused, the credentials were rejected, the budget was
     * spent, or the publish answered with an error. An orphan container is not
     * a post and expires by itself, so this is safe to retry.
     */
    case Abandoned = 'abandoned';

    /**
     * The request left and nothing came back — or came back unusable.
     *
     * §9's asymmetry. The reply may be in the thread and the platform offers no
     * way to ask, so this never resolves itself: a person looks at the thread
     * and says which it was.
     */
    case Unconfirmed = 'unconfirmed';

    /** The operator posted it themselves and wrote that down (§11.1). */
    case RecordedByHand = 'recorded_by_hand';

    /**
     * Whether this attempt still leaves the thread's contents in doubt.
     *
     * The question the duty screen asks, and the reason `in_flight` answers
     * true: an attempt nobody closed is an attempt whose HTTP call may have
     * been the last thing the process did.
     */
    public function isOpenQuestion(): bool
    {
        return match ($this) {
            self::InFlight, self::Unconfirmed => true,
            self::Delivered, self::Abandoned, self::RecordedByHand => false,
        };
    }

    /**
     * The outcomes that leave a doubt, for a `whereIn`.
     *
     * @return list<string>
     */
    public static function openQuestions(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isOpenQuestion()),
        ));
    }
}
