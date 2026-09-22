<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

/** A completed request is distinct from complete coverage of traffic or purchases. */
final readonly class ReadResult
{
    /**
     * @param  list<SearchRow|AnalyticsRow>  $rows
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ReadStatus $status,
        public array $rows = [],
        public ?string $reason = null,
        public array $metadata = [],
    ) {}
}
