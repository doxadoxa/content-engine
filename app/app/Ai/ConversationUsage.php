<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * What a turn spent, apart from what it said.
 *
 * Split out of {@see ConversationResponse} rather than duplicated into
 * {@see ConversationFailed} because both halves of a turn's outcome have to
 * report it in the same shape — the whole point of metering the assistant is
 * that there is one number for what a project cost, and two near-identical
 * quintuples of provider/model/tokens/latency is how that number comes to be
 * assembled twice and differently.
 */
final readonly class ConversationUsage
{
    public function __construct(
        public string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
    ) {}
}
