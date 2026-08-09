<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Research;

use App\Pipelines\Contracts\StepPayload;

/** What research actually added to the pool, and what it left alone. */
final readonly class StoredIdeasPayload implements StepPayload
{
    /**
     * @param  list<string>  $created  keywords that became units
     * @param  list<string>  $skipped  keywords the project already has
     */
    public function __construct(
        public array $created,
        public array $skipped,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['created' => $this->created, 'skipped' => $this->skipped];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            created: array_values(array_map('strval', $data['created'] ?? [])),
            skipped: array_values(array_map('strval', $data['skipped'] ?? [])),
        );
    }
}
