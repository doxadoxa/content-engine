<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

/**
 * Text in, vector out (§8.4).
 *
 * Its own port rather than a role on {@see ModelGateway}: an embedding is not a
 * completion — it returns numbers rather than prose, it is priced per token
 * with no output side, and the model that makes it is chosen for stability
 * rather than quality. Sharing a port would mean one of the two interfaces
 * lying about what it returns.
 */
interface EmbeddingGateway
{
    public function name(): string;

    /** @return list<float> */
    public function embed(string $text): array;

    /** The dimension this gateway produces. Must match the column. */
    public function dimensions(): int;
}
