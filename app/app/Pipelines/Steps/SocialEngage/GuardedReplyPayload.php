<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialEngage;

use App\Pipelines\Contracts\StepPayload;

/**
 * What the run ended up putting in front of the operator.
 *
 * The run row is the only place a reply's history is written down — the
 * conversation row holds the current draft and its findings, and both get
 * overwritten if the draft is thrown away and rewritten. So this records the
 * outcome as it was: whether the reply was offered as sendable, and what was
 * wrong with it if not (§7).
 */
final readonly class GuardedReplyPayload implements StepPayload
{
    /** @param list<string> $findings */
    public function __construct(
        public string $interactionId,
        public bool $sendable,
        public array $findings,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'interaction_id' => $this->interactionId,
            'sendable' => $this->sendable,
            'findings' => $this->findings,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            interactionId: (string) ($data['interaction_id'] ?? ''),
            sendable: (bool) ($data['sendable'] ?? false),
            findings: array_values(array_map('strval', is_array($data['findings'] ?? null) ? $data['findings'] : [])),
        );
    }
}
