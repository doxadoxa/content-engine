<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

/**
 * `search_type` on `GET /keyword_search` (§2).
 *
 * §4.1 listens in `RECENT`, because the listening contour is about what is
 * being said now and a `TOP` ranking is a popularity ordering of things that
 * may be weeks old. `TOP` is here because §5's seasonal and question bands read
 * the same endpoint for a different purpose.
 *
 * ⚠️ Written from Meta's published documentation and not verified against a
 * live account.
 */
enum ThreadsSearchType: string
{
    case Top = 'TOP';

    case Recent = 'RECENT';
}
