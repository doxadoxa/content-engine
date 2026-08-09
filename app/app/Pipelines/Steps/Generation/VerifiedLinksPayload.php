<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Contracts\StepPayload;

/** The draft with its dead citations removed, and what was removed. */
final readonly class VerifiedLinksPayload implements StepPayload
{
    /** @param list<string> $dropped */
    public function __construct(
        public string $markdown,
        public int $kept,
        public array $dropped = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['markdown' => $this->markdown, 'kept' => $this->kept, 'dropped' => $this->dropped];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            markdown: (string) ($data['markdown'] ?? ''),
            kept: (int) ($data['kept'] ?? 0),
            dropped: array_values(array_map('strval', $data['dropped'] ?? [])),
        );
    }
}
