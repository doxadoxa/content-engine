<?php

declare(strict_types=1);

namespace App\Research;

use App\Research\Contracts\KeywordSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * How long an article about this query needs to be, read off what ranks.
 *
 * A fixed target — 1,400 words, chosen once in a wizard — is a guess applied to
 * every topic a project ever writes. "How to clean a marble floor" and "cleaning
 * services in Lisbon" are not the same length of answer, and the pages already
 * winning those searches say so.
 *
 * Target is the higher of the 75th percentile and the median plus a fifth:
 * comfortably above the middle of the results without chasing the one
 * three-thousand-word outlier that drags a mean.
 */
class SerpLength
{
    /** Pages fetched per query. Enough for a median, cheap enough to do often. */
    private const int SAMPLE = 8;

    /** Guards against a page that is an entire book, or a redirect stub. */
    private const int MIN_CREDIBLE = 200;

    private const int MAX_CREDIBLE = 12000;

    public function __construct(private readonly KeywordSource $keywords) {}

    /**
     * The word count to aim for, or null when the SERP could not be read.
     *
     * Null rather than a default, so the caller can fall back to the project's
     * own setting and say which one it used. A silent default here would look
     * exactly like a measurement.
     */
    public function targetFor(string $query, string $market, ?string $language = null): ?int
    {
        $counts = $this->wordCounts($query, $market, $language);

        if (count($counts) < 3) {
            // Two pages is not a distribution.
            return null;
        }

        sort($counts);

        $median = $this->percentile($counts, 0.5);
        $upper = $this->percentile($counts, 0.75);

        return (int) round(max($upper, $median * 1.2));
    }

    /**
     * @return list<int>
     */
    private function wordCounts(string $query, string $market, ?string $language): array
    {
        try {
            // Language matters more here than anywhere else this is called. A
            // target measured off Portuguese pages, applied to an English
            // article, is a number with nothing behind it.
            $pages = $this->keywords->rankingPages($query, $market, self::SAMPLE, $language);
        } catch (\Throwable $e) {
            Log::info('Could not read the SERP for a length target', [
                'query' => $query,
                'reason' => $e->getMessage(),
            ]);

            return [];
        }

        $counts = [];

        foreach ($pages as $page) {
            $words = $this->wordsAt($page['url']);

            if ($words !== null) {
                $counts[] = $words;
            }
        }

        return $counts;
    }

    private function wordsAt(string $url): ?int
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'ContentEngine/1.0 (+length-check)'])
                ->timeout(12)
                ->retry(0)
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        // Scripts and styles stripped before the tags, or a page's inline
        // JavaScript counts as prose and every result looks enormous.
        $html = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $response->body()) ?? '';

        // Split on non-letters rather than str_word_count, which is ASCII-
        // minded: it breaks "manutenção" into two words and undercounts a
        // Portuguese page by a fifth, biasing the whole target downward.
        $words = count(array_filter(
            preg_split('/[^\p{L}\p{N}\'-]+/u', strip_tags($html)) ?: [],
            static fn (string $word): bool => $word !== '',
        ));

        if ($words < self::MIN_CREDIBLE || $words > self::MAX_CREDIBLE) {
            return null;
        }

        return $words;
    }

    /**
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $at): float
    {
        $index = $at * (count($sorted) - 1);
        $low = (int) floor($index);
        $high = (int) ceil($index);

        if ($low === $high) {
            return (float) $sorted[$low];
        }

        // Interpolated, because eight samples put the 75th percentile between
        // two of them more often than not.
        return $sorted[$low] + (($index - $low) * ($sorted[$high] - $sorted[$low]));
    }
}
