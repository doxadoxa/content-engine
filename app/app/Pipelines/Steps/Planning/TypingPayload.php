<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Pipelines\Contracts\StepPayload;

/** Per-unit decisions that do not depend on which units made the month. */
final readonly class TypingPayload implements StepPayload
{
    /**
     * @param  array<string, string>  $types  unit id => ContentItemType value
     * @param  list<string>  $needOriginalData  unit ids
     */
    public function __construct(
        public array $types,
        public array $needOriginalData,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['types' => $this->types, 'need_original_data' => $this->needOriginalData];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            types: $data['types'] ?? [],
            needOriginalData: array_values(array_map('strval', $data['need_original_data'] ?? [])),
        );
    }
}
