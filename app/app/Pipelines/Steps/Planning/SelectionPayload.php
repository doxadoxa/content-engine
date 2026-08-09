<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Pipelines\Contracts\StepPayload;

/** What made the month, and what was left out and why. */
final readonly class SelectionPayload implements StepPayload
{
    /**
     * @param  list<string>  $selected  unit ids, in publishing order
     * @param  array<string, string>  $rejected  unit id => reason
     */
    public function __construct(
        public array $selected,
        public array $rejected = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['selected' => $this->selected, 'rejected' => $this->rejected];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            selected: array_values(array_map('strval', $data['selected'] ?? [])),
            rejected: $data['rejected'] ?? [],
        );
    }
}
