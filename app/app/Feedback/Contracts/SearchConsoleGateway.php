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

    /**
     * The whole property, with no page filter.
     *
     * Exists because `pageReport` can only answer for pages somebody chose to
     * track, and a project that has just connected has chosen none — so the
     * first thing an owner saw after connecting was nothing at all.
     *
     * `$dimension` is one of `date`, `query` or `page`. A `date` report is read
     * to completion; `query` and `page` return Google's top `$rowLimit` rows
     * (sorted by clicks), which is all a ranking list needs.
     *
     * `$property` pins the read to the property a caller captured once at the
     * start of a sync, so the three reports of one sync cannot straddle an
     * owner switching property halfway through. Null means "whatever is
     * selected now".
     */
    public function siteReport(Project $project, Carbon $from, Carbon $to, string $dimension, ?int $rowLimit = null, ?string $property = null): ReadResult;

    public function name(): string;

    /** Whether this project has somewhere to read from. */
    public function isConfiguredFor(Project $project): bool;

    /**
     * @param  list<string>  $urls  the published URLs worth asking about
     * @return list<UnitMetrics>
     */
    public function performance(Project $project, array $urls, Carbon $from, Carbon $to): array;
}
