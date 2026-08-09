<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Pipelines\Contracts\StepPayload;

/** Internal links chosen by nearest neighbour over the corpus (§8.4). */
final readonly class LinksPayload implements StepPayload
{
    /** @param list<array{url: string, anchor: string, distance: float}> $links */
    public function __construct(public array $links) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['links' => $this->links];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        /** @var list<array{url: string, anchor: string, distance: float}> $links */
        $links = $data['links'] ?? [];

        return new self($links);
    }
}
