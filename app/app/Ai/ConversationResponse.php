<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * What the assistant said, what it did on the way, and what the turn cost.
 *
 * `toolCalls` is not a log. It is the record the thread renders — the moment
 * the conversation stopped being talk and changed the project — and it is the
 * answer to "what did it actually do", which was the complaint that produced
 * this whole feature.
 */
final readonly class ConversationResponse
{
    /**
     * @param  list<array{name: string, arguments: array<string, mixed>, result: mixed}>  $toolCalls
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
    ) {}
}
