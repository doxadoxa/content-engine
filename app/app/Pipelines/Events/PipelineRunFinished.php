<?php

declare(strict_types=1);

namespace App\Pipelines\Events;

use App\Models\PipelineRun;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A run reached a terminal state, one way or the other.
 *
 * The engine's only outward signal. Anything that wants to react to a pipeline
 * — chaining the next one, notifying somebody — listens for this rather than
 * being called by the runner, so the runner keeps knowing nothing about what
 * pipelines are for.
 */
class PipelineRunFinished
{
    use Dispatchable;

    public function __construct(public PipelineRun $run) {}
}
