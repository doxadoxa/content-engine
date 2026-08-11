<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\ModelRequest;
use App\Ai\ModelResponse;

/**
 * A metered door to the paid work, supplied by whatever is executing it.
 *
 * `send()` was the whole interface while tokens were the only thing anybody
 * bought through it. They are not: the Studio draws pictures, and an image is
 * priced per picture rather than per token, so it reaches the cost rows only if
 * something records it. It was not being recorded — every generation the Studio
 * made was invisible on the metering page, which §6 says is a sum of step rows
 * and would therefore have been quietly short.
 *
 * `spend()` is here rather than on the pipeline's context because the callers
 * hold a session, not a context: a session that cannot record a purchase is a
 * door with a till on one side only.
 */
interface ModelSession
{
    public function send(ModelRequest $request): ModelResponse;

    /** Record a purchase that has no tokens to count. */
    public function spend(int $costMicros, ?string $provider, ?string $model): void;
}
