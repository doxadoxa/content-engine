<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

/** GA4 reported purchases, not the authoritative paid-order ledger. */
final readonly class AnalyticsRow
{
    public function __construct(
        public string $path,
        public string $day,
        public string $channelGroup,
        public int $sessions,
        public int $purchases,
        public int $grossRevenueMicros,
        public int $refundMicros,
        public int $netRevenueMicros,
        public ?string $currency,
    ) {}
}
