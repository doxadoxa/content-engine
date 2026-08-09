<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Pipelines\Contracts\StepPayload;

/**
 * Candidate signals, before and after the dedup of §4.1.
 *
 * One payload for both steps, because dedup does not change the shape of a
 * candidate — it removes some and says why. `rejected` is keyed by fingerprint
 * rather than by title so that the reason survives the collapse: when four
 * sources deliver one subject, there is one fingerprint and one explanation,
 * which is the level the operator's "чего движок делать не стал и почему" of §7
 * is actually asked at.
 */
final readonly class CandidatePayload implements StepPayload
{
    /**
     * @param  list<CandidateSignal>  $candidates
     * @param  array<string, string>  $rejected  fingerprint => why it was dropped
     */
    public function __construct(
        public array $candidates,
        public array $rejected = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidates' => array_map(
                static fn (CandidateSignal $candidate): array => $candidate->toArray(),
                $this->candidates,
            ),
            'rejected' => $this->rejected,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $rows = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];

        $candidates = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $candidates[] = CandidateSignal::fromArray($row);
            }
        }

        /** @var array<string, string> $rejected */
        $rejected = array_map(strval(...), is_array($data['rejected'] ?? null) ? $data['rejected'] : []);

        return new self($candidates, $rejected);
    }
}
