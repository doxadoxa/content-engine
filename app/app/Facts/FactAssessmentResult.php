<?php

declare(strict_types=1);

namespace App\Facts;

final readonly class FactAssessmentResult
{
    /**
     * @param  list<FactFinding>  $findings
     * @param  array<string, mixed>  $coverage
     * @param  array<string, mixed>  $checker
     * @param  list<string>  $limitations
     */
    public function __construct(
        public string $status,
        public array $findings,
        public array $coverage,
        public array $checker,
        public string $promptHash,
        public array $limitations,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['status' => $this->status, 'findings' => array_map(fn (FactFinding $finding): array => $finding->toArray(), $this->findings),
            'coverage' => $this->coverage, 'checker' => $this->checker, 'prompt_hash' => $this->promptHash, 'limitations' => $this->limitations];
    }
}
