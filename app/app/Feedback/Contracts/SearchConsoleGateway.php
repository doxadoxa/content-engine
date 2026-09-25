<?php

declare(strict_types=1);

namespace App\Feedback\Contracts;

use App\Feedback\Measurements\ReadResult;
use App\Feedback\UnitMetrics;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * Search performance, by URL (§9.1).
 *
 * By URL rather than by unit id, because that is what Search Console knows —
 * and it is why the receiver had to answer with `public_url` back in phase 6.
 * Nothing else joins the two halves of this system.
 *
 * Per project, and the property is not a parameter: Search Console names a site
 * `sc-domain:example.com` or `https://example.com/` — trailing slash included —
 * and which of those this project is stored against is the adapter's business,
 * not a caller's.
 */
interface SearchConsoleGateway
{
    /** @param list<string> $urls */
    public function pageReport(Project $project, array $urls, Carbon $from, Carbon $to, bool $queries = false): ReadResult;

    public function name(): string;

    /** Whether this project has somewhere to read from. */
    public function isConfiguredFor(Project $project): bool;

    /**
     * @param  list<string>  $urls  the published URLs worth asking about
     * @return list<UnitMetrics>
     */
    public function performance(Project $project, array $urls, Carbon $from, Carbon $to): array;
}
