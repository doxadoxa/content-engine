<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use LarAgent\Drivers\OpenAi\OpenAiCompatible;
use OpenAI;

/**
 * `OpenAiCompatible::buildClient()`, with a deadline.
 *
 * Shared by the four drivers that inherit it — the OpenAI-compatible Gemini,
 * Ollama, OpenRouter and the plain compatible driver — because the vendor's
 * version is identical in each and identical in its one flaw: it builds
 * `new Client([])`, which is Guzzle for "wait for ever".
 *
 * A trait rather than an intermediate class, because each of the four already
 * extends a different parent that carries its own `default_url` and formatter.
 * PHP resolves a trait method above an inherited one, so `use`-ing this in a
 * subclass replaces the parent's without touching anything else it does.
 *
 * @see OpenAiCompatible the method this stands in for
 */
trait BoundsOpenAiCompatibleClient
{
    use BoundsModelCalls;

    /**
     * @param  array<string, string>  $headers
     */
    protected function buildClient(string $apiKey, string $baseUrl, array $headers = []): mixed
    {
        $client = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->withHttpClient(self::boundedClient());

        foreach ($headers as $key => $value) {
            $client->withHttpHeader($key, $value);
        }

        return $client->make();
    }
}
