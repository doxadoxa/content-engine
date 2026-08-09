<?php

declare(strict_types=1);

namespace App\Feedback\Contracts;

use App\Feedback\ProjectAudience;
use App\Feedback\UnitEngagement;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * What happened after the click (§9.1, extended).
 *
 * Search Console stops at the search result: it can say an article was seen and
 * clicked, and nothing about whether the person who clicked stayed. That gap is
 * the case this exists for — impressions holding steady while engagement
 * collapses is a page that has stopped answering its own question, and it is
 * invisible from search data alone.
 */
interface AnalyticsGateway
{
    public function name(): string;

    public function isConfiguredFor(Project $project): bool;

    /**
     * @param  list<string>  $urls
     * @return list<UnitEngagement>
     */
    public function engagement(Project $project, array $urls, Carbon $from, Carbon $to): array;

    /**
     * Who arrived, by channel, and what they did (§6).
     *
     * The per-project sibling of {@see engagement()}. "They came without asking
     * Google" is a fact about the project and cannot be assembled from
     * per-URL rows: a session lands on one page and the channel that brought it
     * belongs to the session, not to the page.
     *
     * Null rather than a zeroed value when the property is not connected or the
     * read did not complete, for the reason
     * {@see SearchConsoleGateway::brandDemand()} spells out — the daily row has
     * one column per figure and no room to say "this zero is a guess".
     */
    public function audience(Project $project, Carbon $from, Carbon $to): ?ProjectAudience;
}
