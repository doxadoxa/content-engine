<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialEngage;

use App\Pipelines\Contracts\StepPayload;

/**
 * The §10 verdict on a reply.
 *
 * `required` is carried rather than re-derived downstream because it is a fact
 * about the project at the moment the check ran, and it is what decides whether
 * a finding merely warns the operator or stops the one-tap send.
 */
final readonly class ReplyFactCheckPayload implements StepPayload
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
            findings: array_values(array_map('strval', is_array($data['findings'] ?? null) ? $data['findings'] : [])),
            required: (bool) ($data['required'] ?? false),
        );
    }
}
