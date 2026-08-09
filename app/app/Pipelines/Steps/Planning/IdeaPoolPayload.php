<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Pipelines\Contracts\StepPayload;

/** Ids of the units under consideration, in the order planning prefers them. */
final readonly class IdeaPoolPayload implements StepPayload
{
    /** @param list<string> $ideaIds */
    public function __construct(public array $ideaIds) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['idea_ids' => $this->ideaIds];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(array_values(array_map('strval', $data['idea_ids'] ?? [])));
    }
}
