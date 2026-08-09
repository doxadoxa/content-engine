<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

use App\Enums\PipelineStepStatus;
use App\Pipelines\Contracts\StepPayload;

/**
 * What a step hands back.
 *
 * A step may also simply throw; {@see ErrorClassifier} decides what that meant.
 * Returning is for the outcomes a step *knows* about — this succeeded and here
 * is the payload, or there was nothing to do here and here is why.
 */
final readonly class StepResult
{
    private function __construct(
        public PipelineStepStatus $status,
        public ?StepPayload $output = null,
        public ?string $reason = null,
    ) {}

    public static function success(?StepPayload $output = null): self
    {
        return new self(PipelineStepStatus::Succeeded, $output);
    }

    /**
     * Nothing to do, and that is fine — a derivative step for a channel this
     * project does not publish to, say. Dependants are released as if it had
     * succeeded, so a skip must genuinely mean "unnecessary" and never
     * "impossible": the latter is a failure and has to fail.
     *
     * A skipped step produces no payload, so anything downstream that reads one
     * must guard with {@see StepContext::hasOutput()} and usually skip in turn.
     * Being released is not the same as being given something to work with.
     */
    public static function skip(string $reason): self
    {
        return new self(PipelineStepStatus::Skipped, null, $reason);
    }

    /** @return array<string, mixed>|null */
    public function outputArray(): ?array
    {
        return $this->output?->toArray();
    }
}
