<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Visibility;

use App\Pipelines\Contracts\StepPayload;

/**
 * What the sweep cost and how complete it was.
 *
 * `skippedForBudget` travels rather than being logged and forgotten: a summary
 * built on a truncated sweep is a different claim from one built on a whole
 * sweep, and the screen has to be able to say so.
 */
final readonly class AnsweredPayload implements StepPayload
{
    public function __construct(
        public int $asked,
        public int $declined,
        public int $skippedForBudget,
        public float $moneySpent,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'asked' => $this->asked,
            'declined' => $this->declined,
            'skipped_for_budget' => $this->skippedForBudget,
            'money_spent' => $this->moneySpent,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            asked: (int) ($data['asked'] ?? 0),
            declined: (int) ($data['declined'] ?? 0),
            skippedForBudget: (int) ($data['skipped_for_budget'] ?? 0),
            moneySpent: (float) ($data['money_spent'] ?? 0),
        );
    }
}
