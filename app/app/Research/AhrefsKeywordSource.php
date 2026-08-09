<?php

declare(strict_types=1);

namespace App\Research;

use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Research\Contracts\KeywordSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Ahrefs API v3 (§9: Ahrefs as the keyword source).
 *
 * The spec says "Ahrefs через MCP". This talks to the REST API instead, for one
 * reason: MCP is a protocol for handing tools to a model, and nothing here is a
 * model — the research step wants a list of keywords, not a conversation. When
 * a step does want Ahrefs *as a tool the model can reach for*, LarAgent's MCP
 * client can be pointed at it without this class changing.
 *
 * Unverified against a live account: there is no Ahrefs key in this project yet,
 * so the response mapping below is written from the documented v3 shape and
 * should be checked against a real call before it is trusted. Everything the
 * suite asserts runs through {@see FakeKeywordSource}.
 */
class AhrefsKeywordSource implements KeywordSource
{
    public function name(): string
    {
        return 'ahrefs';
    }

    public function isConfigured(): bool
    {
        return is_string(config('research.ahrefs.token')) && config('research.ahrefs.token') !== '';
    }

    /**
     * `$language` is ignored, and that is the honest answer rather than a gap.
     * Neither matching-terms nor serp-overview takes a language: Ahrefs derives
     * it from the country, so a project selling in Portugal in English cannot
     * be asked for. It is one of the reasons this adapter is being retired.
     *
     * @return list<KeywordIdea>
     */
    public function matchingTerms(string $seed, string $market, int $limit = 50, ?string $language = null): array
    {
        $response = $this->get('/v3/keywords-explorer/matching-terms', [
            'keywords' => $seed,
            'country' => strtolower($market),
            'limit' => $limit,
            // `volume_history` is the monthly curve §5 plans the seasonal band
            // from, and on Ahrefs it has to be *asked for*: unlike DataForSEO,
            // which sends `monthly_searches` unbidden inside every row, this
            // endpoint returns exactly the fields named here and nothing else.
            // A `select` without it makes {@see monthlyCurve()} return `[]`
            // every time — no error, no empty response, just a pool of keywords
            // that all look aseasonal. See {@see measure()}, where that is
            // precisely what happened.
            'select' => 'keyword,volume,difficulty,parent_topic,volume_history',
            'order_by' => 'volume:desc',
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $response['keywords'] ?? [];

        return array_values(array_map(
            static fn (array $row): KeywordIdea => new KeywordIdea(
                keyword: (string) ($row['keyword'] ?? ''),
                volume: (int) ($row['volume'] ?? 0),
                // Ahrefs omits difficulty for long-tail terms rather than
                // sending zero. Zero would read as "trivial to rank for" and
                // send every such keyword to the top of the pool; 50 is the
                // honest "we do not know".
                difficulty: isset($row['difficulty']) ? (int) $row['difficulty'] : 50,
                parentTopic: isset($row['parent_topic']) ? (string) $row['parent_topic'] : null,
                volumeByMonth: self::monthlyCurve($row),
            ),
            array_filter($rows, static fn (array $row): bool => ($row['keyword'] ?? '') !== ''),
        ));
    }

    /**
     * Ahrefs has no per-market language list — its endpoints take a country and
     * infer the rest — so it has no opinion to offer and says so.
     */
    public function languageFor(string $market, ?string $preferred = null): ?string
    {
        return null;
    }

    /**
     * Verified against a live account: keywords-explorer/overview answers in
     * the same shape as matching-terms, so the mapping is shared.
     *
     * One difference that matters and is handled by the caller rather than
     * here: Ahrefs returns a row for a phrase it has never seen, with volume 0
     * and a null difficulty, where DataForSEO omits it. Measured on this
     * project — "rejunte encardido", a topic the reference site publishes,
     * comes back 0/null.
     *
     * @param  list<string>  $keywords
     * @return list<KeywordIdea>
     */
    public function measure(array $keywords, string $market, ?string $language = null): array
    {
        $wanted = array_values(array_unique(array_filter(array_map(trim(...), $keywords))));

        if ($wanted === []) {
            return [];
        }

        $ideas = [];

        // Comma-separated, and a keyword containing a comma would silently
        // become two. Dropped rather than sent, because the second half would
        // come back measured and attributed to a phrase nobody proposed.
        foreach (array_chunk(array_filter($wanted, static fn (string $k): bool => ! str_contains($k, ',')), 100) as $batch) {
            $response = $this->get('/v3/keywords-explorer/overview', [
                'keywords' => implode(',', $batch),
                'country' => strtolower($market),
                // `volume_history` for the same reason {@see matchingTerms()}
                // asks for it, and it was missing here. Every keyword that
                // entered the pool through this method — which is the whole
                // measured half of research — arrived with an empty curve, so
                // `GatherCandidates::seasonal()`'s `whereNotNull` never matched
                // and §5's Season band reported "nothing worth a slot" week
                // after week on any project running Ahrefs. Nothing failed; a
                // band of the plan simply never fired.
                'select' => 'keyword,volume,difficulty,volume_history',
            ]);

            /** @var list<array<string, mixed>> $rows */
            $rows = $response['keywords'] ?? [];

            foreach ($rows as $row) {
                $keyword = $row['keyword'] ?? null;

                if (! is_string($keyword) || $keyword === '') {
                    continue;
                }

                $ideas[] = new KeywordIdea(
                    keyword: $keyword,
                    volume: (int) ($row['volume'] ?? 0),
                    difficulty: isset($row['difficulty']) ? (int) $row['difficulty'] : 50,
                    volumeByMonth: self::monthlyCurve($row),
                );
            }
        }

        return $ideas;
    }

    /**
     * `$language` is ignored; see matchingTerms().
     *
     * @return list<array{url: string, title: string}>
     */
    public function rankingPages(string $keyword, string $market, int $limit = 10, ?string $language = null): array
    {
        $response = $this->get('/v3/serp-overview/serp-overview', [
            'keyword' => $keyword,
            'country' => strtolower($market),
            'select' => 'url,title,position',
            'date' => now()->toDateString(),
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $response['positions'] ?? $response['serp_overview'] ?? [];

        $pages = [];

        foreach ($rows as $row) {
            $url = $row['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $pages[] = ['url' => $url, 'title' => (string) ($row['title'] ?? $url)];

            if (count($pages) >= $limit) {
                break;
            }
        }

        return $pages;
    }

    /**
     * Ahrefs' `volume_history` as a calendar-month curve (§5).
     *
     * The vendor answers `{date, volume}` points over a rolling window, so the
     * same calendar month can appear more than once. Averaged rather than
     * summed, for the reason the DataForSEO adapter gives at the same place: a
     * summed month reads as twice the season it is when the window happens to
     * cover it twice.
     *
     * ⚠️ Written from the vendor's documentation and not verified on a live
     * account, like the rest of this adapter.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, int>
     */
    private static function monthlyCurve(array $row): array
    {
        $points = $row['volume_history'] ?? null;

        if (! is_array($points)) {
            return [];
        }

        /** @var array<int, list<int>> $byMonth */
        $byMonth = [];

        foreach ($points as $point) {
            if (! is_array($point)) {
                continue;
            }

            $date = $point['date'] ?? null;
            $volume = $point['volume'] ?? null;

            if (! is_string($date) || ! is_numeric($volume)) {
                continue;
            }

            $at = strtotime($date);

            // Skipped rather than defaulted: `strtotime()` answers false for a
            // date it cannot read, and falling back to the epoch would file the
            // point under January and invent a winter season out of a parse
            // failure.
            if ($at === false) {
                continue;
            }

            $byMonth[(int) date('n', $at)][] = (int) $volume;
        }

        $curve = [];

        foreach ($byMonth as $month => $volumes) {
            $curve[$month] = (int) round(array_sum($volumes) / count($volumes));
        }

        ksort($curve);

        return $curve;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $base = rtrim((string) config('research.ahrefs.base_url'), '/');

        try {
            $response = Http::withToken((string) config('research.ahrefs.token'))
                ->timeout((int) config('research.ahrefs.timeout', 30))
                ->retry(0)
                ->get($base.$path, $query);
        } catch (ConnectionException $e) {
            throw new RetryableStepFailure("Ahrefs was unreachable: {$e->getMessage()}", previous: $e);
        }

        // Throttling and outages are the step's retry budget to spend; a 4xx
        // that is not a 429 means the request was wrong and will stay wrong.
        if ($response->status() === 429 || $response->serverError()) {
            throw new RetryableStepFailure("Ahrefs answered {$response->status()}.");
        }

        if (in_array($response->status(), [401, 403], true)) {
            // Said in full, because the raw exception is "HTTP request returned
            // status code 401" and the two causes need opposite actions. A
            // queue worker boots the framework once and keeps the config it
            // read then, so editing .env fixes nothing until it restarts —
            // which looks exactly like a bad key.
            throw new TerminalStepFailure(
                "Ahrefs rejected the token ({$response->status()}). Either AHREFS_API_TOKEN is wrong, "
                .'or it was changed after the queue workers started — a worker keeps the configuration '
                .'it booted with, so restart them (`docker compose restart horizon`) and run this again.'
            );
        }

        $response->throw();

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        return $decoded;
    }
}
