<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Pipelines\Contracts\StepPayload;

/** What was written, per channel, before anything was saved. */
final readonly class DerivativesPayload implements StepPayload
{
    /** @param array<string, string> $posts channel type => body */
    public function __construct(public array $posts) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['posts' => $this->posts];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self($data['posts'] ?? []);
    }
}
