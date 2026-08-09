<?php

declare(strict_types=1);

namespace App\Feedback;

/**
 * One window's brand demand for a whole project (§6).
 *
 * The signal §6 leads with, because people typing our name into a search box is
 * what presence means — every other number here is about people who were going
 * somewhere anyway and found us on the way.
 *
 * The matched queries travel with the totals rather than being derivable from
 * them, and that is the whole shape of this type. §6: "число, которое выросло,
 * не сказав какие слова выросли, не действенно". A brand demand of 400 tells an
 * operator nothing they can act on; "«cleaning point lisbon» went from 3 to 90"
 * tells them a city page is missing.
 */
final readonly class BrandDemand
{
    /**
     * @param  array<string, int>  $queries  matched query => impressions, most first
     */
    public function __construct(
        public int $impressions,
        public int $clicks,
        public array $queries,
    ) {}
}
