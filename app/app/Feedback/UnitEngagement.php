<?php

declare(strict_types=1);

namespace App\Feedback;

use Illuminate\Support\Carbon;

/**
 * One day's reading for one URL, from Analytics.
 *
 * Separate from {@see UnitMetrics} rather than added to it, because the two
 * come from different accounts that can be connected independently: a project
 * with Search Console and no GA4 has impressions and no sessions, and a type
 * that carried both would have to lie about one of them.
 */
final readonly class UnitEngagement
{
    public function __construct(
        public string $url,
        public Carbon $measuredOn,
        public int $sessions,
        /** Sessions that lasted, scrolled, or went somewhere — GA4's own definition. */
        public int $engagedSessions,
        /** Seconds of engagement across those sessions. */
        public int $engagementSeconds,
    ) {}
}
