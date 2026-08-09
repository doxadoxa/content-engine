<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Visibility;

use App\Pipelines\Contracts\StepPayload;

/**
 * The sweep's result, as the run recorded it.
 *
 * `score` is nullable and stays nullable through serialisation: "asked, and the
 * brand was in none of them" and "nothing was asked" are opposite facts that
 * both render as 0 if the null is ever coerced away.
 */
final readonly class VisibilitySummaryPayload implements StepPayload
{
    /** @param list<array{locale: string, score: float|null, answered: int, mentions: int, prompts: int}> $byLocale */
    public function __construct(
        public ?float $score,
        public int $mentions,
        public int $answered,
        public array $byLocale,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'mentions' => $this->mentions,
            'answered' => $this->answered,
            'by_locale' => $this->byLocale,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        /** @var list<array{locale: string, score: float|null, answered: int, mentions: int, prompts: int}> $byLocale */
        $byLocale = is_array($data['by_locale'] ?? null) ? array_values($data['by_locale']) : [];

        return new self(
            score: isset($data['score']) && is_numeric($data['score']) ? (float) $data['score'] : null,
            mentions: (int) ($data['mentions'] ?? 0),
            answered: (int) ($data['answered'] ?? 0),
            byLocale: $byLocale,
        );
    }
}
