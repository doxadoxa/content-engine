<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\Contracts\Check;
use App\Audit\Contracts\PageCheck;
use App\Enums\AuditCheckGroup;
use App\Pipelines\Steps\Audit\VerifyLinks;

/**
 * Internal links that go nowhere.
 *
 * Registered as a plain {@see Check} rather than a {@see PageCheck}, and that
 * is the whole design note. Every other page check is a pure function of facts
 * already gathered; this one cannot be, because whether a link is broken is a
 * question only the network answers. Making it a PageCheck would put a request
 * inside the loop that runs every check over every page — a hundred pages times
 * their links, with nothing deduplicating between them.
 *
 * So the fetching lives in {@see VerifyLinks}, which does it once per distinct
 * URL for the whole sweep and under a budget, and this class exists to own the
 * key, the label and the group. The registry hands them to the step and to the
 * screen, so the finding still looks and scores exactly like every other one.
 */
class BrokenLinksCheck implements Check
{
    public static function key(): string
    {
        return 'broken_links';
    }

    public function label(): string
    {
        return 'Broken links';
    }

    public function description(): string
    {
        return 'Follows the internal links on each page and reports the ones that no longer resolve.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Seo;
    }
}
