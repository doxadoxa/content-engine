<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use App\Ai\LaragentModelGateway;
use App\Pipelines\Core\PipelineRunner;
use LarAgent\Core\DTO\DriverConfig;
use LarAgent\Drivers\OpenAi\BaseOpenAiDriver;
use OpenAI;

/**
 * The OpenAI driver, with a deadline.
 *
 * LarAgent's own drivers build their HTTP client with no timeout at all:
 * `OpenAiDriver` calls `OpenAI::client($key)`, which discovers a Guzzle client
 * with Guzzle's defaults, and Guzzle's default `timeout` is `0` — meaning wait
 * for ever. `OpenAiCompatible` is explicit about it and no better: `new
 * Client([])`.
 *
 * **Nothing else in the engine bounds that call.** A step's `timeout()` is not
 * a deadline on the handler — {@see PipelineRunner} uses it
 * to decide when a claim has gone stale and may be taken over, and it cannot
 * interrupt PHP that is sitting in a socket read. The only thing that
 * eventually stops a hung call is the *worker's* timeout, which for
 * `pipeline-expensive` is 2100 seconds. So one unresponsive provider parked a
 * step for thirty-five minutes, holding one of the four workers that bound how
 * much the engine can do at once, while the dashboard drew it as progress.
 *
 * It was found on the site audit's fix plan, because that is the only model
 * call in the product behind a button somebody presses and then watches. Every
 * other step has the same exposure and nobody was there to see it.
 *
 * A timed-out call surfaces through {@see LaragentModelGateway}, which turns
 * any throwable from the provider into a retryable failure — so the step's own
 * retry budget and backoff apply, which is the behaviour that was wanted all
 * along and could never be reached.
 */
class BoundedOpenAiDriver extends BaseOpenAiDriver
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

        // Null rather than an exception, matching `OpenAiDriver`: an
        // installation with no key is a normal state — the suite runs in one —
        // and the base driver already refuses to send with an empty client.
        // Throwing here would move that failure from the call to the boot.
        $this->client = $apiKey === null || $apiKey === ''
            ? null
            : $this->buildClient($apiKey, $config->apiUrl);
    }

    protected function buildClient(string $apiKey, ?string $apiUrl): mixed
    {
        $factory = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient(self::boundedClient());

        if ($apiUrl !== null && $apiUrl !== '') {
            $factory->withBaseUri($apiUrl);
        }

        return $factory->make();
    }
}
