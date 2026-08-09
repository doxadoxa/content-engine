<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a signal came in from (§3).
 *
 * This is the field that makes "does the news loop pay for itself" a number
 * rather than an opinion. Every published unit points at the signal that caused
 * it, every signal names its source, and a month later the question "which of
 * these six intakes produced anything worth reading" is a group-by — the same
 * way §9 settles everything else in the engine.
 *
 * Kept separate from {@see SignalKind} on purpose: the kind is what the reason
 * is, the source is what it cost to find. Only one of those is a budget line.
 */
enum SignalSource: string
{
    /** `GET /keyword_search` — free, first-party, and better than any external trend feed (§2). */
    case ThreadsKeywordSearch = 'threads_keyword_search';

    /** The platform pushed us an event about a new reply. */
    case ThreadsWebhook = 'threads_webhook';

    /** `threads_manage_insights` — our own numbers coming back as a reason to act. */
    case ThreadsInsights = 'threads_insights';

    /** The project's own RSS whitelist (§4.1). */
    case Rss = 'rss';

    /** The keyword pool the research pipeline already fills. Costs nothing extra. */
    case KeywordPool = 'keyword_pool';

    /** A gap or an anniversary found in what the project has already published. */
    case Corpus = 'corpus';

    /** An operator, or the business itself: a price change, a new case. */
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::ThreadsKeywordSearch => 'Threads keyword search',
            self::ThreadsWebhook => 'Threads webhook',
            self::ThreadsInsights => 'Threads insights',
            self::Rss => 'RSS',
            self::KeywordPool => 'Keyword pool',
            self::Corpus => 'Corpus',
            self::Business => 'Business',
        };
    }
}
