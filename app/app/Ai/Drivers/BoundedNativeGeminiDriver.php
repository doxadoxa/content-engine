<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Drivers\Gemini\GeminiDriver;

/**
 * Gemini's own API, with a deadline.
 *
 * The parent builds Guzzle directly with a base URI and auth headers and no
 * timeout, and offers no hook to change that, so the client is rebuilt after
 * construction.
 *
 * The base URI comes from the parent's own property; the two headers are
 * restated. `Client::getConfig()` would avoid the duplication and is deprecated
 * — it goes away in Guzzle 8 — so this takes the duplication instead and names
 * the risk: if LarAgent changes the headers this driver sends, they have to
 * change here too. BoundedDriversTest keeps at least the auth
 * header honest.
 */
class BoundedNativeGeminiDriver extends GeminiDriver
{
    use BoundsModelCalls;

    /**
     * @param  DriverConfig|array<string, mixed>  $settings
     */
    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);

        $this->httpClient = self::boundedClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey,
            ],
        ]);
    }
}
