<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\SiteCheck;
use App\Audit\SiteSignals;
use App\Enums\AuditCheckGroup;

/**
 * Whether robots.txt exists and lets the site be read.
 *
 * The high finding here is the most expensive single fault the audit can
 * report: a wildcard `Disallow: /` left behind after a staging deploy takes an
 * entire site out of every index, and it is invisible from the browser. Every
 * article the engine writes for such a site is money spent on a page nothing
 * will ever fetch.
 */
class RobotsTxtCheck implements SiteCheck
{
    public static function key(): string
    {
        return 'robots_txt';
    }

    public function label(): string
    {
        return 'Robots.txt';
    }

    public function description(): string
    {
        return 'Checks that robots.txt exists, does not block the whole site, and points at the sitemap.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Seo;
    }

    /** @return list<CheckFinding> */
    public function run(SiteSignals $signals): array
    {
        if (! $signals->hasRobots()) {
            return [CheckFinding::medium(
                'There is no robots.txt at the root of the site.',
                ['status' => $signals->robotsStatus],
            )];
        }

        if ($signals->robotsBlocksEverything()) {
            return [CheckFinding::high(
                'robots.txt tells every crawler to stay out of the whole site.',
                ['directive' => 'User-agent: * / Disallow: /'],
            )];
        }

        // Not fatal, and deliberately low: a sitemap is discoverable from
        // /sitemap.xml without it. It is worth a line because the reference to
        // it is free and it is the one hint that survives a site moving its
        // sitemap somewhere unguessable.
        if (preg_match('/^\s*sitemap:\s*\S+/im', (string) $signals->robotsBody) !== 1) {
            return [CheckFinding::low('robots.txt does not name a sitemap.')];
        }

        return [];
    }
}
