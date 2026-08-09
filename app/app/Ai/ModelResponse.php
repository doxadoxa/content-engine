<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * What came back, and what it cost to get.
 *
 * The token counts are not optional and not nullable. §1 makes metering a
 * day-one requirement, and a gateway that may or may not report usage produces
 * a cost table with holes in it — which is indistinguishable from a cost table
 * showing cheap steps.
 */
final readonly class ModelResponse
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public string $role,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
