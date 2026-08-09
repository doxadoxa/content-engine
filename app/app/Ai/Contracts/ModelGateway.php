<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\ModelRequest;
use App\Ai\ModelResponse;

/**
 * The one door to a language model (§3.3).
 *
 * Every model call in the engine goes through this and nothing calls a provider
 * SDK directly. Two things depend on that being true rather than mostly true:
 * the test suite never reaches the network in any phase, because the fake is
 * bound in its place; and every call is metered, because the one implementation
 * that makes calls is also the one that reports tokens.
 */
interface ModelGateway
{
    public function send(ModelRequest $request): ModelResponse;
}
