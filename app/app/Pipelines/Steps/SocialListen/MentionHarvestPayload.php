<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Pipelines\Contracts\StepPayload;

/**
 * The reconciliation pass over replies and mentions (§4.1).
 *
 * `recovered` is the number that justifies the step existing at all. The
 * webhook receiver writes conversations in real time and this sweep re-reads
 * the same endpoint an hour later, so on a healthy installation `recovered` is
 * zero every hour — and a run where it is not is a run that found an event Meta
 * never delivered, which is the only evidence anybody will ever get that the
 * subscription is lossy.
 */
final readonly class MentionHarvestPayload implements StepPayload
{
    /**
     * @param  list<array<string, mixed>>  $replies  every reply seen, whether or not it was new
     * @param  int  $recovered  conversations this pass created that the webhook had not
     */
    public function __construct(
        public array $replies,
        public int $recovered,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['replies' => $this->replies, 'recovered' => $this->recovered];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        /** @var list<array<string, mixed>> $replies */
        $replies = array_values(array_filter(
            is_array($data['replies'] ?? null) ? $data['replies'] : [],
            is_array(...),
        ));

        return new self(
            replies: $replies,
            recovered: (int) ($data['recovered'] ?? 0),
        );
    }
}
