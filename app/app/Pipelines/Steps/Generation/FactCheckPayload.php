<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Contracts\StepPayload;

/**
 * The verdict. `passed` is what blocks a YMYL unit from reaching draft (§5.2),
 * so it is a field rather than an inference from the notes.
 */
final readonly class FactCheckPayload implements StepPayload
{
    /** @param list<string> $findings */
    public function __construct(
        public bool $passed,
        public array $findings,
        public bool $required,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['passed' => $this->passed, 'findings' => $this->findings, 'required' => $this->required];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            passed: (bool) ($data['passed'] ?? false),
            findings: array_values(array_map('strval', $data['findings'] ?? [])),
            required: (bool) ($data['required'] ?? false),
        );
    }
}
