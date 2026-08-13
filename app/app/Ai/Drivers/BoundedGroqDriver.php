<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Drivers\Groq\GroqDriver;
use LucianoTonet\GroqPHP\Groq;

/**
 * Groq, with a deadline.
 *
 * The odd one out: Groq's SDK builds its own transport and takes the deadline
 * as an option in *milliseconds*, so there is no Guzzle client to hand it and
 * {@see BoundsModelCalls::timeoutMilliseconds()} does the conversion.
 */
class BoundedGroqDriver extends GroqDriver
{
    use BoundsModelCalls;

    /**
     * @param  DriverConfig|array<string, mixed>  $settings
     */
    public function __construct(DriverConfig|array $settings = [])
    {
        parent::__construct($settings);

        $config = $this->getDriverConfig();
        $apiKey = $config->apiKey;

        if ($apiKey === null || $apiKey === '') {
            return;
        }

        $options = ['timeout' => self::timeoutMilliseconds()];

        if ($config->apiUrl !== null && $config->apiUrl !== '') {
            $options['baseUrl'] = rtrim($config->apiUrl, '/');
        }

        $this->client = new Groq($apiKey, $options);
    }
}
