<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialPlan;

use App\Pipelines\Contracts\StepPayload;
use App\Social\GovernorVerdict;

/**
 * The governor's verdict, crossing a step boundary (§4.3).
 *
 * A wrapper rather than making {@see GovernorVerdict} a `StepPayload` itself:
 * the verdict is asked for by the publisher and by whatever renders §7's
 * summary, neither of which is a pipeline, and a domain object that implements
 * a pipeline interface teaches the next reader that it is one.
 */
final readonly class GovernorPayload implements StepPayload
{
    public function __construct(public GovernorVerdict $verdict) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->verdict->toArray();
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        return new self(GovernorVerdict::fromArray($data));
    }
}
