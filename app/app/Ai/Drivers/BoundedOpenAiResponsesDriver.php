<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Drivers\OpenAi\OpenAiResponsesDriver;
use OpenAI;

/**
 * OpenAI's Responses API, with a deadline.
 *
 * The parent builds `OpenAI::client($key)` — the discovered Guzzle, with no
 * timeout — so the client is replaced after construction rather than through a
 * hook, because this driver has none.
 */
class BoundedOpenAiResponsesDriver extends OpenAiResponsesDriver
{
    use BoundsModelCalls;

    /**
     * @param  DriverConfig|array<string, mixed>  $settings
     */
    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);

        $apiKey = $this->getDriverConfig()->apiKey;

        // Null when there is no key, matching the parent: an installation
        // without credentials is a normal state, and the driver already refuses
        // to send with an empty client.
        $this->client = $apiKey === null || $apiKey === ''
            ? null
            : OpenAI::factory()
                ->withApiKey($apiKey)
                ->withHttpClient(self::boundedClient())
                ->make();
    }
}
