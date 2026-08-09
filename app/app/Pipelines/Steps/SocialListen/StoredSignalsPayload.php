<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Pipelines\Contracts\StepPayload;

/** What the listening run actually wrote (§4.1). */
final readonly class StoredSignalsPayload implements StepPayload
{
    /**
     * @param  list<string>  $signalIds  every row this run wrote or refreshed
     * @param  list<string>  $questionIds  the subset §1.3 sends to the article planner
     */
    public function __construct(
        public array $signalIds,
        public array $questionIds,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['signal_ids' => $this->signalIds, 'question_ids' => $this->questionIds];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            signalIds: array_values(array_map(strval(...), is_array($data['signal_ids'] ?? null) ? $data['signal_ids'] : [])),
            questionIds: array_values(array_map(strval(...), is_array($data['question_ids'] ?? null) ? $data['question_ids'] : [])),
        );
    }
}
