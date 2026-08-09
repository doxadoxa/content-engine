<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Interaction;

/**
 * Why a conversation was deliberately left alone (§7).
 *
 * §7 makes the reason mandatory and says why: "Молчащий автомат неотличим от
 * сломанного, и единственная вещь, которая делает «сегодня не публикуем»
 * приемлемой, — объяснение рядом." That is written about the engine, and it is
 * just as true of the operator — a queue that empties with no record of why is
 * a queue nobody can audit and a signal nobody can learn from.
 *
 * A closed set rather than free text, for the reason the approvals dialog gives
 * about rejections: this is countable. "Not for us" forty times in a week is a
 * fact about the listening contour's filtering; forty different sentences are
 * forty sentences. Stored in `interactions.ignored_reason` by
 * {@see Interaction::ignore()}.
 */
enum InteractionSkipReason: string
{
    /** Spam, a bot, or an account with nothing to say to. */
    case Spam = 'spam';

    /** A real person, but not talking to or about us. */
    case NotForUs = 'not_for_us';

    /** Somebody already answered it, on the platform or elsewhere. */
    case AlreadyAnswered = 'already_answered';

    /** Needs a person who is not the social operator — support, legal, the founder. */
    case NeedsSomebodyElse = 'needs_somebody_else';

    /** Answerable, but nothing we would say publicly is worth saying. */
    case NothingWorthSaying = 'nothing_worth_saying';

    /** Too old to be worth answering: §4.2's first hour is long gone. */
    case TooLate = 'too_late';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam or a bot',
            self::NotForUs => 'Not about us',
            self::AlreadyAnswered => 'Already answered elsewhere',
            self::NeedsSomebodyElse => 'Needs somebody else',
            self::NothingWorthSaying => 'Nothing worth saying publicly',
            self::TooLate => 'Too late to be worth it',
        };
    }
}
