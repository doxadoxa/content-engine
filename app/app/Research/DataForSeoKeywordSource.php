<?php

declare(strict_types=1);

namespace App\Research;

use App\Research\Contracts\KeywordSource;
use App\Research\DataForSeo\DataForSeoClient;
use App\Research\DataForSeo\DataForSeoLocations;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DataForSEO v3 as the keyword source (§4.1), replacing Ahrefs.
 *
 * Why the switch, in the two numbers that decided it. Measured against the real
 * account rather than the price list: an Ahrefs matching-terms row costs 21–22
 * API units, so a 50-keyword seed spends ~1,100 of a 100,000-unit Lite plan —
 * about €1.31, and roughly ninety seed lookups is the whole month. The same
 * lookup here is $0.0103 for the task plus $0.00012 per keyword: $0.016. A SERP
 * read goes from 50 units (Ahrefs charges a per-request floor) to $0.002.
 *
 * The port did its job. This is a sibling of {@see AhrefsKeywordSource} and
 * nothing upstream of the interface knows the difference — which is also why
 * both stay bound behind one config key while the numbers are checked.
 *
 * Two things genuinely differ under the hood, and both are handled rather than
 * papered over: DataForSEO answers 200 for almost every failure (see
 * {@see DataForSeoClient}), and it wants the language said out loud (see
 * {@see DataForSeoLocations}).
 *
 * Confirmed against live responses on 2026-08-07: `keyword_info.search_volume` and
 * `keyword_properties.keyword_difficulty` are where this reads them, and the
 * difficulty is real — a median of 21 against Ahrefs' 50, which was this
 * codebase's "not measured" sentinel rather than a number.
 */
class DataForSeoKeywordSource implements KeywordSource
{
    /**
     * What Ahrefs meant by "we have no difficulty for this keyword".
     *
     * Kept identical on purpose. Zero would read as "trivial to rank for" and
     * send every unmeasured long-tail to the top of the pool, straight past
     * `maximum_difficulty`; 50 is the honest middle.
     *
     * This is not hypothetical. Ahrefs returns a literal `0` rather than null
     * across Portuguese terms — `limpeza`, 14,000 searches a month, comes back
     * difficulty 0 — so the guard it had never fired for this project's market
     * and the difficulty filter passed everything. DataForSEO sends null, which
     * is the distinction the guard was written for.
     */
    private const int DIFFICULTY_UNKNOWN = 50;

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly DataForSeoLocations $locations,
    ) {}

    public function name(): string
    {
        return 'dataforseo';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /** @return list<KeywordIdea> */
    public function matchingTerms(string $seed, string $market, int $limit = 50, ?string $language = null): array
    {
        $seed = trim($seed);

        if ($seed === '') {
            return [];
        }

        $endpoint = config('research.dataforseo.keyword_endpoint') === 'ideas'
            ? '/v3/dataforseo_labs/google/keyword_ideas/live'
            : '/v3/dataforseo_labs/google/keyword_suggestions/live';

        $ideasEndpoint = str_contains($endpoint, 'keyword_ideas');

        $task = [
            // The two endpoints differ in exactly this: suggestions expands one
            // phrase, ideas takes a set and answers about their category.
            ...($ideasEndpoint ? ['keywords' => [$seed]] : ['keyword' => $seed]),
            'location_code' => $this->locations->codeFor($market),
            // Ahrefs' matching-terms included the seed itself and the pool's
            // ordering assumes it is there, so suggestions is asked to keep it.
            //
            // Only suggestions. `keyword_ideas` has no such parameter — it
            // answers about a category rather than expanding a phrase — and
            // this API rejects a request outright rather than ignoring a field
            // it does not know, which would be a 40501 on every research run.
            ...($ideasEndpoint ? [] : ['include_seed_keyword' => true]),
            'limit' => max(1, min($limit, 1000)),
            'order_by' => ['keyword_info.search_volume,desc'],
        ];

        $tag = $this->locations->languageFor($market, $language);

        if ($tag !== null) {
            $task['language_code'] = $tag;
        }

        $ideas = [];
        $measured = 0;

        foreach ($this->items($this->client->post($endpoint, $task)) as $item) {
            $keyword = $item['keyword'] ?? null;

            if (! is_string($keyword) || trim($keyword) === '') {
                continue;
            }

            if (is_array($item['keyword_info'] ?? null)) {
                $measured++;
            }

            /** @var array<string, mixed> $info */
            $info = is_array($item['keyword_info'] ?? null) ? $item['keyword_info'] : [];
            /** @var array<string, mixed> $properties */
            $properties = is_array($item['keyword_properties'] ?? null) ? $item['keyword_properties'] : [];

            $difficulty = $properties['keyword_difficulty'] ?? null;
            $core = $properties['core_keyword'] ?? null;

            $ideas[] = new KeywordIdea(
                keyword: $keyword,
                // Null means "not measured", which is a different fact from
                // zero and the reason nothing here uses `?? 0`. Both end up as
                // 0 for the volume floor, but only because a keyword nobody can
                // measure fails that floor for the same reason a keyword nobody
                // searches does.
                volume: is_numeric($info['search_volume'] ?? null) ? (int) $info['search_volume'] : 0,
                difficulty: is_numeric($difficulty) ? (int) $difficulty : self::DIFFICULTY_UNKNOWN,
                // DataForSEO leaves core_keyword null when the keyword *is* the
                // core of its group, which is the same thing our clustering
                // means by null — it falls back to the keyword itself.
                parentTopic: is_string($core) && $core !== '' ? $core : null,
                // What the vendor answered in, which is not always what was
                // asked for: a market with no corpus in the requested language
                // is answered in its own, and the article has to follow.
                language: $tag ?? $this->languageFor($market, $language),
                // Already in the response, unasked for and until now thrown
                // away — which is exactly what §5 means by seasonality being a
                // field and a method rather than an integration.
                volumeByMonth: $this->monthlyCurve($info),
            );
        }

        // Keywords came back and not one of them carried a metrics block.
        //
        // Said out loud because the two explanations look identical downstream
        // and only one of them is about the market: either this seed really is
        // that obscure, or the response nests one level deeper than the mapping
        // above expects — `keyword_data.keyword_info` rather than
        // `keyword_info`, which is how a sibling Labs endpoint answers. The
        // second reads as every keyword having zero volume, so the pool empties
        // against `minimum_volume` and research reports a thin market rather
        // than a wrong field path.
        if ($ideas !== [] && $measured === 0) {
            Log::warning('DataForSEO returned keywords with no metrics at all — check the response shape', [
                'endpoint' => $endpoint,
                'seed' => $seed,
                'keywords' => count($ideas),
            ]);
        }

        return $ideas;
    }

    public function languageFor(string $market, ?string $preferred = null): ?string
    {
        // The same resolution measure() uses, so a caller that proposes in this
        // language is guaranteed the measurement will be taken in it too.
        return $this->locations->languageFor($market, $preferred)
            ?? $this->locations->defaultLanguageFor($market);
    }

    /**
     * Volumes from Google Ads, difficulty from Labs where Labs knows the phrase.
     *
     * Two endpoints because they answer about different worlds, and the first
     * version of this used only the second and was quietly useless. Labs'
     * `keyword_overview` reports on phrases already in DataForSEO's own
     * database; asked about twenty topics taken straight off a competitor's
     * published calendar — "rejunte encardido", "cheiro a tabaco", "limpeza
     * para expatriados" — it recognised two. Not because the topics are
     * invented, but because a crawled keyword database has never heard of the
     * long tail.
     *
     * Google Ads answers about any phrase, which is the whole point of
     * proposing phrases nothing suggested. It charges $0.075 flat for up to a
     * thousand of them and returns a row for every one, with null where it has
     * no figure — which is the "we have never seen this" the contract asks to
     * be omitted rather than reported as zero.
     *
     * It has no notion of ranking difficulty, so that is enriched from Labs
     * afterwards for whatever Labs does know, and left as unknown otherwise.
     * Best effort by design: a difficulty lookup failing must not throw away
     * volumes that are already bought and paid for.
     *
     * @return list<KeywordIdea>
     */
    public function measure(array $keywords, string $market, ?string $language = null): array
    {
        // The endpoints' shared limits: 80 characters, ten words. Anything
        // longer is a sentence rather than a query, and one of them rejects the
        // whole batch rather than the offending phrase.
        $wanted = array_values(array_unique(array_filter(
            array_map(trim(...), $keywords),
            // Words counted by splitting on non-letters rather than with
            // str_word_count, which is ASCII-minded: it reads "limpeza pós-obra"
            // as four words where this reads two, so the limit would bite
            // unevenly on exactly the market this is for. The same bug was
            // already fixed once in SerpLength.
            static fn (string $keyword): bool => $keyword !== ''
                && mb_strlen($keyword) <= 80
                && count(preg_split('/[^\p{L}\p{N}]+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: []) <= 10,
        )));

        if ($wanted === []) {
            return [];
        }

        $location = $this->locations->codeFor($market);
        $tag = $this->languageFor($market, $language);

        $volumes = [];
        $curves = [];

        foreach (array_chunk($wanted, 1000) as $batch) {
            foreach ($this->rows('/v3/keywords_data/google_ads/search_volume/live', [
                'keywords' => $batch,
                'location_code' => $location,
                'language_code' => $tag,
            ]) as $row) {
                $keyword = $row['keyword'] ?? null;
                $volume = $row['search_volume'] ?? null;

                // Null is "no figure", not "nobody searches". Omitted, so the
                // caller can tell an invented phrase from a tiny real one.
                if (is_string($keyword) && $keyword !== '' && is_numeric($volume)) {
                    $volumes[$keyword] = (int) $volume;
                    // This endpoint carries the curve on the row itself rather
                    // than under a `keyword_info` block.
                    $curves[$keyword] = $this->monthlyCurve($row);
                }
            }
        }

        if ($volumes === []) {
            return [];
        }

        $difficulty = $this->difficultyFor(array_keys($volumes), $location, $tag);

        $ideas = [];

        foreach ($volumes as $keyword => $volume) {
            $ideas[] = new KeywordIdea(
                keyword: (string) $keyword,
                volume: $volume,
                difficulty: $difficulty[$keyword] ?? self::DIFFICULTY_UNKNOWN,
                language: $tag,
                volumeByMonth: $curves[$keyword] ?? [],
            );
        }

        return $ideas;
    }

    /**
     * @return list<array{url: string, title: string}>
     */
    public function rankingPages(string $keyword, string $market, int $limit = 10, ?string $language = null): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        $task = [
            'keyword' => $keyword,
            'location_code' => $this->locations->codeFor($market),
            // Billed per ten results, so asking for the next ten to throw eight
            // of them away costs real money on every article.
            'depth' => max(10, (int) ceil($limit / 10) * 10),
        ];

        if (($tag = $this->locations->languageFor($market, $language)) !== null) {
            $task['language_code'] = $tag;
        }

        $pages = [];

        foreach ($this->items($this->client->post('/v3/serp/google/organic/live/advanced', $task)) as $item) {
            // One flat array holds the whole result page — organic results sit
            // alongside people_also_ask, ai_overview, local_pack and the rest.
            // Ahrefs marked those with a null url and this adapter's ancestor
            // dropped them by accident; here they are typed, so they get
            // dropped on purpose.
            if (($item['type'] ?? null) !== 'organic') {
                continue;
            }

            $url = $item['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $title = $item['title'] ?? null;

            $pages[] = ['url' => $url, 'title' => is_string($title) && $title !== '' ? $title : $url];

            if (count($pages) >= $limit) {
                break;
            }
        }

        return $pages;
    }

    /**
     * DataForSEO's `monthly_searches` as a calendar-month curve (§5).
     *
     * The vendor answers a rolling window of about twelve `{year, month,
     * search_volume}` points, so a month can appear twice when the window
     * straddles a year boundary. Averaged rather than summed for that reason: a
     * summed December would read as twice the season it is, purely because of
     * when the question was asked.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, int>
     */
    private function monthlyCurve(array $row): array
    {
        $points = $row['monthly_searches'] ?? null;

        if (! is_array($points)) {
            return [];
        }

        /** @var array<int, list<int>> $byMonth */
        $byMonth = [];

        foreach ($points as $point) {
            if (! is_array($point)) {
                continue;
            }

            $month = $point['month'] ?? null;
            $volume = $point['search_volume'] ?? null;

            if (! is_numeric($month) || ! is_numeric($volume)) {
                continue;
            }

            $month = (int) $month;

            if ($month < 1 || $month > 12) {
                continue;
            }

            $byMonth[$month][] = (int) $volume;
        }

        $curve = [];

        foreach ($byMonth as $month => $volumes) {
            $curve[$month] = (int) round(array_sum($volumes) / count($volumes));
        }

        ksort($curve);

        return $curve;
    }

    /**
     * Ranking difficulty for whatever Labs recognises, and nothing for the rest.
     *
     * @param  list<string>  $keywords
     * @return array<string, int>
     */
    private function difficultyFor(array $keywords, int $location, ?string $language): array
    {
        $found = [];

        try {
            foreach (array_chunk($keywords, 700) as $batch) {
                foreach ($this->rows('/v3/dataforseo_labs/google/keyword_overview/live', [
                    'keywords' => $batch,
                    'location_code' => $location,
                    'language_code' => $language,
                ]) as $row) {
                    $keyword = $row['keyword'] ?? null;
                    $properties = is_array($row['keyword_properties'] ?? null) ? $row['keyword_properties'] : [];
                    $score = $properties['keyword_difficulty'] ?? null;

                    if (is_string($keyword) && is_numeric($score)) {
                        $found[$keyword] = (int) $score;
                    }
                }
            }
        } catch (Throwable $e) {
            // The volumes are already bought. Losing them because the enrichment
            // failed would be paying for an answer and then discarding it.
            Log::info('Could not enrich proposed topics with difficulty', ['reason' => $e->getMessage()]);
        }

        return $found;
    }

    /**
     * The keyword rows of one task, whichever way the family nests them.
     *
     * The two DataForSEO APIs disagree, and the disagreement is silent. Labs
     * answers with one result carrying `items`; Keywords Data answers with the
     * rows *as* the result. Reading the wrong one returns an empty list rather
     * than an error — which is how the Google Ads call came back recognising
     * nothing at all, for phrases it had perfectly good figures for.
     *
     * @param  array<string, mixed>  $task
     * @return list<array<string, mixed>>
     */
    private function rows(string $path, array $task): array
    {
        $result = $this->client->post($path, $task);

        if ($result !== [] && is_array($result[0]['items'] ?? null)) {
            return $this->items($result);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($result, 'is_array'));

        return $rows;
    }

    /**
     * The rows inside a result.
     *
     * Both endpoints nest the same way — one result per task, `items` inside
     * it — and both can answer with a result that has no items at all, which is
     * an empty SERP rather than a broken response.
     *
     * @param  list<array<string, mixed>>  $result
     * @return list<array<string, mixed>>
     */
    private function items(array $result): array
    {
        if ($result === [] || ! is_array($result[0]['items'] ?? null)) {
            return [];
        }

        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter($result[0]['items'], 'is_array'));

        return $items;
    }
}
