<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\SiteCheck;
use App\Audit\SiteSignals;
use App\Enums\AuditCheckGroup;
use App\Support\Corpus\SiteLibrary;

/**
 * Whether the sitemap exists and lists anything.
 *
 * High rather than medium when it is missing, because the sitemap is also this
 * engine's own way in: {@see SiteLibrary} builds the
 * planner's picture of what the site already covers from it, and the audit's
 * own crawl has nothing to crawl without it. A missing sitemap does not only
 * cost rankings — it quietly narrows what the whole product can see.
 */
class SitemapCheck implements SiteCheck
{
    public static function key(): string
    {
        return 'sitemap_xml';
    }

    public function label(): string
    {
        return 'Sitemap';
    }

    public function description(): string
    {
        return 'Validates that sitemap.xml is present, parses, and lists the pages of the site.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Seo;
    }

    /** @return list<CheckFinding> */
    public function run(SiteSignals $signals): array
    {
        if ($signals->sitemapStatus !== 200) {
            return [CheckFinding::high(
                'The sitemap could not be read, so search engines have no index of the site to follow.',
                ['sitemap_url' => $signals->sitemapUrl, 'status' => $signals->sitemapStatus],
            )];
        }

        if ($signals->sitemapUrls === []) {
            return [CheckFinding::high(
                'The sitemap is there but names no pages.',
                ['sitemap_url' => $signals->sitemapUrl],
            )];
        }

        return [];
    }
}
