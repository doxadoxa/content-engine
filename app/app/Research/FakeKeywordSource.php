<?php

declare(strict_types=1);

namespace App\Research;

use App\Research\Contracts\KeywordSource;

/**
 * The keyword source the suite runs against (§4.1, and the roadmap's rule that
 * no phase's tests reach the network).
 *
 * Deterministic on purpose. Exit criterion 1 asks for a *reproducible* pool, so
 * a source that returned random volumes would make the criterion untestable —
 * the same seed and market always produce the same keywords in the same order.
 */
class FakeKeywordSource implements KeywordSource
{
    /** @var array<string, list<KeywordIdea>> */
    private array $scripted = [];

    /** @var list<array{seed: string, market: string, limit: int, language: string|null}> */
    private array $asked = [];

    /** @var list<array{keyword: string, market: string, language: string|null}> */
    private array $ranked = [];

    /** @var list<array{url: string, title: string}> */
    private array $ranking = [];

    /** @var array<string, array{0: int, 1: int}> */
    private array $measurements = [];

    private ?string $marketLanguage = null;

    /** @var list<array{keywords: list<string>, market: string, language: string|null}> */
    private array $measured = [];

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @param  list<KeywordIdea>  $ideas
     */
    public function willReturn(string $seed, array $ideas): self
    {
        $this->scripted[$seed] = $ideas;

        return $this;
    }

    /**
     * @param  list<array{url: string, title: string}>  $pages
     */
    public function willRank(array $pages): self
    {
        $this->ranking = $pages;

        return $this;
    }

    /**
     * @return list<array{url: string, title: string}>
     */
    public function rankingPages(string $keyword, string $market, int $limit = 10, ?string $language = null): array
    {
        $this->ranked[] = ['keyword' => $keyword, 'market' => $market, 'language' => $language];

        return array_slice($this->ranking, 0, $limit);
    }

    /** @return list<KeywordIdea> */
    public function matchingTerms(string $seed, string $market, int $limit = 50, ?string $language = null): array
    {
        $this->asked[] = ['seed' => $seed, 'market' => $market, 'limit' => $limit, 'language' => $language];

        if (isset($this->scripted[$seed])) {
            return array_slice($this->scripted[$seed], 0, $limit);
        }

        return array_slice($this->derive($seed), 0, $limit);
    }

    /** The language the market has data in, when the fixture wants one. */
    public function willSpeak(?string $language): self
    {
        $this->marketLanguage = $language;

        return $this;
    }

    public function languageFor(string $market, ?string $preferred = null): ?string
    {
        return $this->marketLanguage ?? $preferred;
    }

    /**
     * Script what a phrase measures at. Anything unscripted is treated as a
     * phrase the vendor has never seen and is omitted, which is the behaviour
     * the proposing step depends on to tell a real topic from an invented one.
     *
     * @param  array<string, array{0: int, 1: int}>  $volumes  keyword => [volume, difficulty]
     */
    public function willMeasure(array $volumes): self
    {
        $this->measurements = $volumes;

        return $this;
    }

    /** @return list<KeywordIdea> */
    public function measure(array $keywords, string $market, ?string $language = null): array
    {
        $this->measured[] = ['keywords' => $keywords, 'market' => $market, 'language' => $language];

        $ideas = [];

        foreach ($keywords as $keyword) {
            if (! isset($this->measurements[$keyword])) {
                continue;
            }

            [$volume, $difficulty] = $this->measurements[$keyword];

            $ideas[] = new KeywordIdea($keyword, $volume, $difficulty);
        }

        return $ideas;
    }

    /** @return list<array{keywords: list<string>, market: string, language: string|null}> */
    public function measured(): array
    {
        return $this->measured;
    }

    /** @return list<array{seed: string, market: string, limit: int, language: string|null}> */
    public function asked(): array
    {
        return $this->asked;
    }

    /** @return list<array{keyword: string, market: string, language: string|null}> */
    public function ranked(): array
    {
        return $this->ranked;
    }

    /**
     * Plausible variations of the seed, generated from it rather than sampled.
     *
     * The volumes descend and the difficulties climb, so a test that asserts
     * "the pool is ordered by opportunity" is asserting something the ordering
     * code did rather than something the fixture arranged.
     *
     * @return list<KeywordIdea>
     */
    private function derive(string $seed): array
    {
        $shapes = [
            '%s' => 'root',
            'how to %s' => 'how',
            'best %s' => 'best',
            '%s cost' => 'cost',
            '%s vs alternatives' => 'vs',
            'why %s' => 'why',
            '%s near me' => 'near',
            '%s checklist' => 'checklist',
        ];

        $ideas = [];
        $index = 0;

        foreach ($shapes as $shape => $tag) {
            $ideas[] = new KeywordIdea(
                keyword: sprintf($shape, $seed),
                volume: 4_000 - ($index * 400),
                difficulty: 5 + ($index * 7),
                parentTopic: $seed,
                entities: [$seed, $tag],
            );

            $index++;
        }

        return $ideas;
    }
}
