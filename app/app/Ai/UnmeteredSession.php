<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\ModelGateway;
use App\Ai\Contracts\ModelSession;
use App\Pipelines\Core\StepContext;

/**
 * The model door for work that is not a pipeline step.
 *
 * Named for what it costs you rather than for what it does. Every call the
 * engine makes on a project's behalf lands on a `pipeline_steps` row, which is
 * what makes §6's per-unit cost a sum of rows rather than an estimate — and a
 * call through this one does not. It is still metered by the provider and still
 * charged; it is simply not attributed to any run.
 *
 * So the rule is narrow: maintenance commands only, where a human typed the
 * command and is standing there when it finishes. Anything the scheduler starts
 * has a run to hang its cost on and must use that run's
 * {@see StepContext} instead.
 */
final readonly class UnmeteredSession implements ModelSession
{
    public function __construct(private ModelGateway $gateway) {}

    public function send(ModelRequest $request): ModelResponse
    {
        return $this->gateway->send($request);
    }

    /**
     * Deliberately nothing, which is what this class is named for.
     *
     * A purchase made outside a run has no step row to land on. It was still
     * paid for and still charged by the provider; it is simply not attributed,
     * exactly as the tokens above are not.
     */
    public function spend(int $costMicros, ?string $provider, ?string $model): void {}
}
