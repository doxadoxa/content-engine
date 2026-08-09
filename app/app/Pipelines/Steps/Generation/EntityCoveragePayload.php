<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Contracts\StepPayload;

/**
 * Which of the unit's entities the body actually mentions.
 *
 * §5.3 asks for entity coverage "проверяемое, а не декларативное" — checkable,
 * not declared. So this is measured against the text rather than asserted by
 * the model that wrote it.
 */
final readonly class EntityCoveragePayload implements StepPayload
{
    /** @param array<string, bool> $coverage entity => present */
    public function __construct(public array $coverage) {}

    public function ratio(): float
    {
        if ($this->coverage === []) {
            return 1.0;
        }

        return count(array_filter($this->coverage)) / count($this->coverage);
    }

    /** @return list<string> */
    public function missing(): array
    {
        return array_keys(array_filter($this->coverage, static fn (bool $seen): bool => ! $seen));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['coverage' => $this->coverage];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self($data['coverage'] ?? []);
    }
}
