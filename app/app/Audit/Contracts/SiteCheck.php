<?php

declare(strict_types=1);

namespace App\Audit\Contracts;

use App\Audit\CheckFinding;
use App\Audit\SiteSignals;

/**
 * A check about the site as a whole: its robots.txt, its sitemap, its TLS.
 *
 * Its findings hang off the audit rather than off any page, because there is no
 * page they are about.
 */
interface SiteCheck extends Check
{
    /** @return list<CheckFinding> */
    public function run(SiteSignals $signals): array;
}
