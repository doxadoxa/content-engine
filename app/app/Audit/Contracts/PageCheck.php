<?php

declare(strict_types=1);

namespace App\Audit\Contracts;

use App\Audit\CheckFinding;
use App\Audit\PageFacts;

/**
 * A check that reads one page.
 *
 * It is handed facts, not HTML and not a URL to fetch: the page was read once
 * by the crawler, and a check that could reach the network would make the cost
 * of a sweep a function of how many checks are registered.
 */
interface PageCheck extends Check
{
    /** @return list<CheckFinding> */
    public function run(PageFacts $page): array;
}
