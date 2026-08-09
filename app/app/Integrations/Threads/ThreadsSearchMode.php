<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

/**
 * `search_mode` on `GET /keyword_search` (§2).
 *
 * Here rather than in `App\Enums` on purpose: this is a wire value of one
 * vendor's endpoint, not a word the engine's own domain speaks. Nothing outside
 * this adapter has an opinion about it, and `App\Enums` is where the vocabulary
 * shared across the engine lives.
 *
 * ⚠️ Written from Meta's published documentation and not verified against a
 * live account.
 */
enum ThreadsSearchMode: string
{
    /** Free text. What §4.1 runs the project's entities through. */
    case Keyword = 'KEYWORD';

    /** A hashtag, without the `#`. §4.1 asks for both modes each hour. */
    case Tag = 'TAG';
}
