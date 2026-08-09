<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialEngage;

use App\Pipelines\Contracts\StepPayload;

/**
 * One reply, as the model wrote it (§4.2).
 *
 * One, not the five-to-ten of `draft_candidates` in §4.3. The economics are the
 * other way round here: a weak post costs the account reach for weeks, which is
 * what buys the candidate pool, whereas a weak reply is read by one person and
 * edited by another before it goes anywhere. §4.2 spends the budget on latency
 * instead — "целевая задержка — минуты".
 */
final readonly class ReplyDraftPayload implements StepPayload
{
    public function __construct(public string $text) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['text' => $this->text];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(text: (string) ($data['text'] ?? ''));
    }
}
