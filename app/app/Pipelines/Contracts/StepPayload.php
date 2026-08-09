<?php

declare(strict_types=1);

namespace App\Pipelines\Contracts;

/**
 * The typed output of a step (§3.1: "явными входом и выходом (типизированные
 * DTO)").
 *
 * A step's output crosses a process boundary — it is written to a json column
 * and read back by another worker, possibly days later — so it cannot simply be
 * an object. These two methods are that round trip, and having them on a DTO
 * rather than passing bare arrays is what lets a consuming step say which shape
 * it expects and be wrong loudly rather than with an undefined array key.
 */
interface StepPayload
{
    /** @return array<string, mixed> */
    public function toArray(): array;

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static;
}
