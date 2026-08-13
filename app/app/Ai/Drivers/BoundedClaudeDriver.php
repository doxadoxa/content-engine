<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use Anthropic;
use LarAgent\Drivers\Anthropic\ClaudeDriver;

/**
 * Claude, with a deadline.
 *
 * Its own class rather than the compatible trait: Anthropic's SDK has its own
 * factory, and `ClaudeDriver::buildClient()` takes two arguments where the
 * OpenAI-compatible one takes three.
 */
class BoundedClaudeDriver extends ClaudeDriver
{
    use BoundsModelCalls;

    protected function buildClient(string $apiKey, string $baseUrl): mixed
    {
        return Anthropic::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->withHttpClient(self::boundedClient())
            ->make();
    }
}
