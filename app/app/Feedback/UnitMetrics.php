<?php

declare(strict_types=1);

namespace App\Feedback;

use Illuminate\Support\Carbon;

/** One day's reading for one URL. */
final readonly class UnitMetrics
{
    public function __construct(
        public string $url,
        public Carbon $measuredOn,
        public int $impressions,
        public int $clicks,
        public ?float $position,
    ) {}
}
