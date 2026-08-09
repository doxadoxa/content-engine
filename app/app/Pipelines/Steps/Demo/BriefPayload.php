<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Demo;

use App\Pipelines\Contracts\StepPayload;

/** What the run was asked for, normalised. */
final readonly class BriefPayload implements StepPayload
{
    public function __construct(
        public string $topic,
        public string $tone,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['topic' => $this->topic, 'tone' => $this->tone];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self((string) ($data['topic'] ?? ''), (string) ($data['tone'] ?? ''));
    }
}
