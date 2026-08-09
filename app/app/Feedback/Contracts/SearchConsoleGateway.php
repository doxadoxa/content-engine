<?php

declare(strict_types=1);

namespace App\Feedback\Contracts;

use App\Feedback\BrandDemand;
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
    public function name(): string;

    /** Whether this project has somewhere to read from. */
    public function isConfiguredFor(Project $project): bool;

    /**
     * @param  list<string>  $urls  the published URLs worth asking about
     * @return list<UnitMetrics>
     */
    public function performance(Project $project, array $urls, Carbon $from, Carbon $to): array;

    /**
     * How many people searched for us by name, and which words they used (§6).
     *
     * The per-project sibling of {@see performance()}. Presence is not a
     * property of a URL: nobody types a URL, and the query dimension is the one
     * place Search Console will say who was looking for *us* rather than for a
     * subject we happen to rank on.
     *
     * **Null is not zero, and this is the reason the return type is nullable at
     * all.** `performance()` can answer an empty list for a refusal because a
     * caller writing per-unit rows simply writes none. This one feeds a single
     * daily row, where a refused read and a day nobody searched are the same
     * shape — a `BrandDemand(0, 0, [])` for a broken connection would put a
     * trough in the trend that never happened, and the trend is the only thing
     * §6 stores this for. So: a value when a read happened, null when there was
     * nothing to read from or the read did not complete.
     */
    public function brandDemand(Project $project, Carbon $from, Carbon $to): ?BrandDemand;
}
