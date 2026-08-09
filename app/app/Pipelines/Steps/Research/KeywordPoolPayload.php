<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Research;

use App\Pipelines\Contracts\StepPayload;
use App\Research\KeywordIdea;

/**
 * A pool of keywords passing between research steps.
 *
 * Carries {@see KeywordIdea} objects rather than arrays so the steps downstream
 * keep the typing, and flattens to arrays only at the json column boundary.
 */
final readonly class KeywordPoolPayload implements StepPayload
{
    /**
     * @param  list<KeywordIdea>  $keywords
     * @param  array<string, string>  $intents  keyword => intent value
     * @param  array<string, string>  $clusters  keyword => cluster label
     */
    public function __construct(
        public array $keywords,
        public array $intents = [],
        public array $clusters = [],
        public string $source = '',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'intents' => $this->intents,
            'clusters' => $this->clusters,
            'keywords' => array_map(static fn (KeywordIdea $idea): array => [
                'keyword' => $idea->keyword,
                'volume' => $idea->volume,
                'difficulty' => $idea->difficulty,
                'parent_topic' => $idea->parentTopic,
                'entities' => $idea->entities,
                'language' => $idea->language,
                // Carried for the same reason `language` is: the step that
                // plans a seasonal post is downstream of the one that learned
                // the curve, and §5 wants it planned four to six weeks ahead of
                // a peak this is the only record of.
                'volume_by_month' => $idea->volumeByMonth,
            ], $this->keywords),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $data['keywords'] ?? [];

        return new self(
            keywords: array_map(static fn (array $row): KeywordIdea => new KeywordIdea(
                keyword: (string) $row['keyword'],
                volume: (int) $row['volume'],
                difficulty: (int) $row['difficulty'],
                parentTopic: $row['parent_topic'] === null ? null : (string) $row['parent_topic'],
                // Carried across the json boundary, because the step that
                // decides an article's language is three steps downstream of
                // the one that learned it.
                language: isset($row['language']) ? (string) $row['language'] : null,
                entities: array_values(array_map('strval', $row['entities'] ?? [])),
                // Month keys survive the json round trip as strings, so they
                // are cast back: a curve keyed "12" sorts and compares
                // differently from one keyed 12, and the peak lookup wants ints.
                volumeByMonth: self::curveFrom($row['volume_by_month'] ?? []),
            ), $rows),
            intents: $data['intents'] ?? [],
            clusters: $data['clusters'] ?? [],
            source: (string) ($data['source'] ?? ''),
        );
    }

    /**
     * @return array<int, int>
     */
    private static function curveFrom(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $curve = [];

        foreach ($raw as $month => $volume) {
            if (is_numeric($month) && is_numeric($volume)) {
                $curve[(int) $month] = (int) $volume;
            }
        }

        return $curve;
    }
}
