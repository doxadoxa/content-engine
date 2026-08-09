<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Feedback;

use App\Pipelines\Contracts\StepPayload;

/**
 * What a fetch found, and for how many units.
 *
 * Shared by both fetches rather than one each: they answer the same shape of
 * question about the same units, and {@see DetectDegradation} merges whichever
 * of the two actually ran.
 */
final readonly class MetricsPayload implements StepPayload
{
    /** @param list<string> $unitIds */
    public function __construct(
        public array $unitIds,
        public int $rows,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['unit_ids' => $this->unitIds, 'rows' => $this->rows];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            unitIds: array_values(array_map('strval', $data['unit_ids'] ?? [])),
            rows: (int) ($data['rows'] ?? 0),
        );
    }
}
