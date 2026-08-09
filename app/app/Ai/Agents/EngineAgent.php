<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\LaragentModelGateway;
use LarAgent\Agent;

/**
 * A one-shot LarAgent agent the gateway configures at call time.
 *
 * Steps are stateless and their prompts are assembled from their dependencies'
 * output, so there is nothing for a per-agent subclass to hold: instructions,
 * provider and model all arrive from {@see LaragentModelGateway}.
 * History is in memory and every call gets a fresh session key, so no two steps
 * can contaminate each other's context.
 */
class EngineAgent extends Agent
{
    /** @var string The parent declares string|array; only the driver alias is used. */
    protected $history = 'in_memory';

    private string $systemInstructions = 'You are a precise assistant working inside an automated content pipeline.';

    public function instructions(): string
    {
        return $this->systemInstructions;
    }

    public function withInstructions(string $instructions): static
    {
        if (trim($instructions) !== '') {
            $this->systemInstructions = $instructions;
        }

        return $this;
    }

    /**
     * Exposes the parent's provider switch, so the provider comes from
     * config/models.php rather than from a subclass per provider.
     */
    public function usingProvider(string $provider): static
    {
        $this->changeProvider($provider);

        return $this;
    }
}
