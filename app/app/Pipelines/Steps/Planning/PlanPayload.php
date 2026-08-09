<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Pipelines\Contracts\StepPayload;

/** The plan that came out, for whoever reads the run. */
final readonly class PlanPayload implements StepPayload
{
    public function __construct(
        public string $planId,
        public string $month,
        public int $units,
        public int $locales,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_id' => $this->planId,
            'month' => $this->month,
            'units' => $this->units,
            'locales' => $this->locales,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            planId: (string) ($data['plan_id'] ?? ''),
            month: (string) ($data['month'] ?? ''),
            units: (int) ($data['units'] ?? 0),
            locales: (int) ($data['locales'] ?? 0),
        );
    }
}
