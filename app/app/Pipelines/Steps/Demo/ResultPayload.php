<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Demo;

use App\Pipelines\Contracts\StepPayload;

/** What the fan-in step assembled from both branches. */
final readonly class ResultPayload implements StepPayload
{
    public function __construct(
        public string $headline,
        public int $words,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['headline' => $this->headline, 'words' => $this->words];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self((string) ($data['headline'] ?? ''), (int) ($data['words'] ?? 0));
    }
}
