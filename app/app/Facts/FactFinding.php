<?php

declare(strict_types=1);

namespace App\Facts;

/** A proposed assessment, not an owner-confirmed assertion. End offsets are exclusive Unicode codepoints. */
final readonly class FactFinding
{
    /** @param list<string> $referenceIds */
    public function __construct(
        public string $sectionKey,
        public string $exactQuote,
        public int $startCodepoint,
        public int $endCodepoint,
        public string $relation,
        public ?string $factVersionId,
        public string $reason,
        public array $referenceIds,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
