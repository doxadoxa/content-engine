<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

/**
 * One row of a whole-property Search Console report.
 *
 * `day` is set for a `[date]` report and `key` for a `[query]` or `[page]`
 * one — never both, because the site reports ask for one dimension at a time.
 * Crossing them would multiply the rows by the number of days and the top-250
 * cut would stop meaning "the top 250 of the window".
 */
final readonly class SiteSearchRow
{
    public function __construct(
        public ?string $day,
        public ?string $key,
        public int $impressions,
        public int $clicks,
        public ?float $position,
    ) {}
}
