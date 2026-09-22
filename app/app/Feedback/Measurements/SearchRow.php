<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

final readonly class SearchRow
{
    public function __construct(
        public string $url,
        public string $day,
        public int $impressions,
        public int $clicks,
        public ?float $position,
        public ?string $query = null,
    ) {}
}
