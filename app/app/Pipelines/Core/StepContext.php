<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

use App\Ai\Contracts\ModelGateway;
use App\Ai\Contracts\ModelSession;
use App\Ai\ModelRequest;
use App\Ai\ModelResponse;
use App\Media\GeneratedImage;
use App\Media\HeroImage;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Contracts\StepPayload;
use App\Pipelines\Exceptions\TerminalStepFailure;

/**
 * Everything a step is allowed to see, and the only way it reaches a model.
 *
 * The model call goes through here rather than through an injected gateway so
 * that usage is metered by construction: a step cannot spend tokens without the
 * runner learning what they cost, because the accounting happens on the way
 * through rather than by the step remembering to report it.
 */
final class StepContext implements ModelSession
{
    /** @var list<ModelResponse> */
    private array $usage = [];

    /** @var list<array{cost_micros: int, provider: string, model: string}> */
    private array $spend = [];

    /** @var array<string, mixed> */
    private array $remembered = [];

    /**
     * @param  array<string, mixed>  $input  what the run was started with
     * @param  array<string, array<string, mixed>|null>  $dependencyOutputs  keyed by step key
     * @param  array<string, mixed>  $runContext
     */
    public function __construct(
        public readonly PipelineRun $run,
        public readonly Project $project,
        private readonly array $input,
        private readonly array $dependencyOutputs,
        private readonly array $runContext,
        private readonly ModelGateway $gateway,
    ) {}

    /** @return array<string, mixed> */
    public function input(): array
    {
        return $this->input;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->input, $key, $default);
    }

    /**
     * A dependency's output, as the type it promised.
     *
     * The step key is asked for explicitly rather than inferred, because a step
     * may legitimately read a dependency of a dependency — the DAG constrains
     * ordering, not visibility.
     *
     * @template T of StepPayload
     *
     * @param  class-string<T>  $payload
     * @return T
     */
    public function output(string $stepKey, string $payload): StepPayload
    {
        $data = $this->dependencyOutputs[$stepKey] ?? null;

        if ($data === null) {
            // Terminal, not retryable: a missing dependency output is either a
            // step that returned nothing or a DAG that let this run too early.
            // Neither improves by being attempted again in thirty seconds.
            throw new TerminalStepFailure(
                "Step `{$stepKey}` produced no output for this step to read. Either it was "
                .'skipped — in which case guard with hasOutput() and skip too — or it is not '
                ."among this step's dependencies."
            );
        }

        return $payload::fromArray($data);
    }

    public function hasOutput(string $stepKey): bool
    {
        return ($this->dependencyOutputs[$stepKey] ?? null) !== null;
    }

    /**
     * Ask a model. `$role` names a role in config/models.php, never a model.
     */
    public function ask(string $role, string $prompt, string $instructions = ''): ModelResponse
    {
        $response = $this->gateway->send(new ModelRequest($role, $instructions, $prompt));

        $this->usage[] = $response;

        return $response;
    }

    public function send(ModelRequest $request): ModelResponse
    {
        $response = $this->gateway->send($request);

        $this->usage[] = $response;

        return $response;
    }

    /**
     * Run-level state written by earlier steps. Distinct from a dependency's
     * output: this is for the handful of facts the whole run shares, not for
     * what one step produced for another.
     */
    public function recall(string $key, mixed $default = null): mixed
    {
        return data_get($this->runContext, $key, $default);
    }

    /**
     * Money spent on something that is not tokens.
     *
     * Images, and today only images. {@see GeneratedImage} has
     * always carried its own price — "image models are priced per picture, not
     * per token, so the provider reports money directly" — and there was
     * nowhere for it to go: `meter()` reads {@see usage()}, `usage()` holds
     * model responses, and an image is not one. So `illustrate_draft` recorded
     * `cost_micros = 0` while buying four pictures, which is the single largest
     * per-article cost in the product, and §6's per-unit number was wrong by
     * most of itself.
     *
     * Reported rather than intercepted, and that is a step down from how tokens
     * work — `ask()` meters by construction because a step cannot reach a model
     * except through it, whereas this asks the step to remember. The honest
     * reason is that {@see HeroImage} is injected into three steps
     * and returns a file, and routing it through here would be a port shaped
     * for text stretched over something that is not. The mitigation is that
     * there is exactly one place to buy a picture, so there is exactly one
     * return value to follow.
     */
    public function spend(int $costMicros, string $provider, string $model): void
    {
        if ($costMicros <= 0) {
            return;
        }

        $this->spend[] = [
            'cost_micros' => $costMicros,
            'provider' => $provider,
            'model' => $model,
        ];
    }

    /** @return list<array{cost_micros: int, provider: string, model: string}> */
    public function spent(): array
    {
        return $this->spend;
    }

    public function remember(string $key, mixed $value): void
    {
        $this->remembered[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function remembered(): array
    {
        return $this->remembered;
    }

    /** @return list<ModelResponse> */
    public function usage(): array
    {
        return $this->usage;
    }
}
