<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Pipelines\Contracts\StepPayload;

/**
 * The one candidate that is left, or the sentence explaining why none is
 * (§4.3, §7).
 *
 * Both shapes are successes. §4.3 makes the empty slot a valid result of this
 * pipeline — "в этом всё отличие от генератора" — so a pool where nothing
 * cleared the week's bar produces a `candidate` of null and a `refusal` an
 * operator can read, rather than a failed run or the least-bad post.
 *
 * `scores` carries the whole ranking and not only the winner's. It is what
 * makes "why that one" answerable, and what makes the throttled week's raised
 * bar visible after the fact: the same pool with a floor of 70 instead of 50
 * leaves the same numbers behind and a different decision.
 */
final readonly class ChosenCandidatePayload implements StepPayload
{
    /**
     * @param  list<int>  $scores  every candidate's score, in pool order
     * @param  int  $bar  the week's selection floor, as it stood for this run
     */
    public function __construct(
        public ?PostCandidate $candidate,
        public int $score,
        public int $bar,
        public bool $throttled,
        public array $scores,
        public ?string $refusal = null,
    ) {}

    public function wasChosen(): bool
    {
        return $this->candidate !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidate' => $this->candidate?->toArray(),
            'score' => $this->score,
            'bar' => $this->bar,
            'throttled' => $this->throttled,
            'scores' => $this->scores,
            'refusal' => $this->refusal,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        $candidate = $data['candidate'] ?? null;

        return new self(
            candidate: is_array($candidate) ? PostCandidate::fromArray($candidate) : null,
            score: (int) ($data['score'] ?? 0),
            bar: (int) ($data['bar'] ?? 0),
            throttled: (bool) ($data['throttled'] ?? false),
            scores: array_values(array_map(
                intval(...),
                is_array($data['scores'] ?? null) ? $data['scores'] : [],
            )),
            refusal: is_string($data['refusal'] ?? null) && $data['refusal'] !== ''
                ? $data['refusal']
                : null,
        );
    }
}
