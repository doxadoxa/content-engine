<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What sort of reason to say something a signal is (§3).
 *
 * The kind is about the *shape* of the reason, not about where it came from —
 * {@see SignalSource} answers that. The two are separate because the same kind
 * arrives from several places (a question comes from `keyword_search`, from the
 * keyword pool and from a reply under one of our own posts) and the feedback
 * loop of §3 has to be able to slice either way.
 */
enum SignalKind: string
{
    /** Somebody asked something. §5 calls this the best format there is. */
    case Question = 'question';

    /** Something happened. Perishable, which is why signals carry a TTL. */
    case News = 'news';

    /** The brand was named by somebody who is not us. */
    case Mention = 'mention';

    /** A reply under one of our own posts — the duty queue's raw material. */
    case Reply = 'reply';

    /** A subject picking up volume right now. */
    case Trend = 'trend';

    /** A yearly curve says this is four to six weeks from its peak (§5). */
    case Seasonal = 'seasonal';

    /** Prices, cases, real customer questions: the fact nobody else has (§5). */
    case OwnData = 'own_data';

    /** An article that already exists and has something short left to say. */
    case Repurpose = 'repurpose';

    public function label(): string
    {
        return match ($this) {
            self::Question => 'Question',
            self::News => 'News',
            self::Mention => 'Mention',
            self::Reply => 'Reply',
            self::Trend => 'Trend',
            self::Seasonal => 'Seasonal',
            self::OwnData => 'Own data',
            self::Repurpose => 'Repurpose',
        };
    }
}
