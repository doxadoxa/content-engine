<?php

declare(strict_types=1);

namespace App\Feedback\Contracts;

use App\Feedback\Measurements\ReadResult;
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
    /** @param list<string> $urls */
    public function landingPurchases(Project $project, array $urls, Carbon $from, Carbon $to): ReadResult;

    public function name(): string;

    public function isConfiguredFor(Project $project): bool;

    /**
     * @param  list<string>  $urls
     * @return list<UnitEngagement>
     */
    public function engagement(Project $project, array $urls, Carbon $from, Carbon $to): array;
}
