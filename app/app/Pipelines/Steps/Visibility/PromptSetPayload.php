<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Visibility;

use App\Pipelines\Contracts\StepPayload;

/**
 * Which languages this project is measured in, and how many prompts were new.
 *
 * The locale list travels rather than being re-derived downstream, so the
 * summary reports on exactly the set that was asked — a project whose locales
 * changed mid-run would otherwise show a denominator it never measured.
 */
final readonly class PromptSetPayload implements StepPayload
{
    /** @param list<string> $locales */
    public function __construct(
        public array $locales,
        public int $written,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['locales' => $this->locales, 'written' => $this->written];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            locales: array_values(array_map('strval', is_array($data['locales'] ?? null) ? $data['locales'] : [])),
            written: (int) ($data['written'] ?? 0),
        );
    }
}
