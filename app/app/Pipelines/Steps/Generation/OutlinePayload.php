<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Contracts\StepPayload;

/** Section headings, in order. */
final readonly class OutlinePayload implements StepPayload
{
    /** @param list<string> $sections */
    public function __construct(public array $sections) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['sections' => $this->sections];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(array_values(array_map('strval', $data['sections'] ?? [])));
    }
}
