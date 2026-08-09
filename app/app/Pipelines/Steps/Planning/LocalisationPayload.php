<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Pipelines\Contracts\StepPayload;

/** How many locale rows were given their own language, and how many were not. */
final readonly class LocalisationPayload implements StepPayload
{
    public function __construct(
        public int $localised,
        public int $untranslated,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'localised' => $this->localised,
            'untranslated' => $this->untranslated,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            localised: (int) ($data['localised'] ?? 0),
            untranslated: (int) ($data['untranslated'] ?? 0),
        );
    }
}
