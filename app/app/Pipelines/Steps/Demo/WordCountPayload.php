<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Demo;

use App\Pipelines\Contracts\StepPayload;

final readonly class WordCountPayload implements StepPayload
{
    public function __construct(public int $words) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['words' => $this->words];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self((int) ($data['words'] ?? 0));
    }
}
