<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Pipelines\Contracts\StepPayload;

/**
 * The five to ten posts that were written so that one could be published
 * (§4.3, §8).
 *
 * The whole pool is carried forward and stored, not only the winner. §8 costs
 * "опубликованный пост, а не сгенерированный" — at one in eight a post costs
 * eight generations — and a run that discarded the seven losers before the
 * output was written would leave the report unable to say how many there were,
 * which is the half of the arithmetic that makes the number surprising.
 *
 * It is also what §7 needs to answer "why this one". A slot whose only record
 * is the text that survived cannot explain a choice; a slot that kept the pool
 * and the scores can.
 */
final readonly class CandidatePoolPayload implements StepPayload
{
    /**
     * @param  list<PostCandidate>  $candidates  in the order they were written
     * @param  int  $calls  model calls made, which is what §8 is costing
     */
    public function __construct(
        public array $candidates,
        public int $calls,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidates' => array_map(
                static fn (PostCandidate $candidate): array => $candidate->toArray(),
                $this->candidates,
            ),
            'calls' => $this->calls,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        $raw = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];

        $candidates = [];

        foreach ($raw as $candidate) {
            if (is_array($candidate)) {
                $candidates[] = PostCandidate::fromArray($candidate);
            }
        }

        return new self(
            candidates: $candidates,
            calls: (int) ($data['calls'] ?? count($candidates)),
        );
    }
}
