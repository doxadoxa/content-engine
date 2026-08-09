<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Feedback;

use App\Pipelines\Contracts\StepPayload;

/** What the loop concluded. */
final readonly class SignalsPayload implements StepPayload
{
    /**
     * @param  array<string, string>  $refreshing  unit id => reason
     * @param  array<string, float>  $clusterScores  cluster => clicks per live unit
     */
    public function __construct(
        public array $refreshing,
        public array $clusterScores = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['refreshing' => $this->refreshing, 'cluster_scores' => $this->clusterScores];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            refreshing: $data['refreshing'] ?? [],
            clusterScores: $data['cluster_scores'] ?? [],
        );
    }
}
