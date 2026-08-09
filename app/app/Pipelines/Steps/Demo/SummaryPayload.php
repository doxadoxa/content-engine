<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Demo;

use App\Pipelines\Contracts\StepPayload;

/** The model's answer, and which model gave it. */
final readonly class SummaryPayload implements StepPayload
{
    public function __construct(
        public string $summary,
        public string $model,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['summary' => $this->summary, 'model' => $this->model];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self((string) ($data['summary'] ?? ''), (string) ($data['model'] ?? ''));
    }
}
