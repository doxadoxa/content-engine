<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Agents\EngineAgent;
use App\Ai\Contracts\ModelGateway;
use App\Pipelines\Exceptions\RetryableStepFailure;
use Illuminate\Support\Str;
use Throwable;

/**
 * The real gateway (§3.3): LarAgent in front of whichever provider the role
 * resolves to.
 *
 * This class is the only place in the engine that talks to a model, which is
 * what makes "the suite never reaches the network" enforceable — the test
 * environment binds {@see FakeModelGateway} over the interface and there is no
 * second path to bypass.
 */
class LaragentModelGateway implements ModelGateway
{
    public function __construct(private readonly ModelCatalog $catalog) {}

    public function send(ModelRequest $request): ModelResponse
    {
        $choice = $this->catalog->resolve($request->role);

        $agent = EngineAgent::for('step-'.Str::random(16))
            ->usingProvider($choice->provider)
            ->withInstructions($request->instructions)
            ->withModel($choice->model)
            ->trackUsage(true);

        if (isset($request->options['temperature'])) {
            $agent->temperature((float) $request->options['temperature']);
        }

        if (isset($request->options['max_tokens'])) {
            $agent->maxCompletionTokens((int) $request->options['max_tokens']);
        }

        $startedAt = hrtime(true);

        try {
            $answer = $agent->respond($request->prompt);
        } catch (Throwable $e) {
            // LarAgent re-throws the vendor SDK's exception as-is, and those
            // carry no status code this can inspect. Transient is the useful
            // default here — unlike an unrecognised exception inside a step,
            // which is usually a bug, an exception on the wire is usually the
            // wire. The step's retry budget still bounds it.
            throw new RetryableStepFailure(
                "The {$choice->provider} call failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $usage = $agent->usageStorage()?->getLastUsage();

        return new ModelResponse(
            text: $this->asText($answer),
            provider: $choice->provider,
            model: $usage === null ? $choice->model : ($usage->modelName ?: $choice->model),
            role: $request->role,
            // Zero rather than null when the provider reported nothing: the
            // column is not nullable on purpose, and a zero next to a non-empty
            // answer reads as "this provider did not tell us", which is true.
            inputTokens: $usage === null ? 0 : (int) $usage->promptTokens,
            outputTokens: $usage === null ? 0 : (int) $usage->completionTokens,
            latencyMs: $latencyMs,
        );
    }

    private function asText(mixed $answer): string
    {
        if (is_string($answer)) {
            return $answer;
        }

        if (is_array($answer)) {
            return (string) json_encode($answer);
        }

        if (is_object($answer) && method_exists($answer, 'toArray')) {
            return (string) json_encode($answer->toArray());
        }

        return (string) json_encode($answer);
    }
}
