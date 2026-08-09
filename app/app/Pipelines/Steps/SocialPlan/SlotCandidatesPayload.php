<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialPlan;

use App\Pipelines\Contracts\StepPayload;

/** Everything the week could say, and what it could not (§4.3, §7). */
final readonly class SlotCandidatesPayload implements StepPayload
{
    /**
     * @param  list<SlotCandidate>  $candidates  ranked, best first
     * @param  array<string, string>  $empty  band value => why that band had nothing
     */
    public function __construct(
        public array $candidates,
        public array $empty = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidates' => array_map(
                static fn (SlotCandidate $candidate): array => $candidate->toArray(),
                $this->candidates,
            ),
            'empty' => $this->empty,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = is_array($data['candidates'] ?? null) ? array_values($data['candidates']) : [];
        /** @var array<string, string> $empty */
        $empty = is_array($data['empty'] ?? null) ? array_map(strval(...), $data['empty']) : [];

        return new self(
            candidates: array_map(SlotCandidate::fromArray(...), $raw),
            empty: $empty,
        );
    }
}
