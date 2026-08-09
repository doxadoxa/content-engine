<?php

declare(strict_types=1);

namespace App\Research\DataForSeo;

use App\Pipelines\Exceptions\TerminalStepFailure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns a project's market and locale into the two numbers DataForSEO asks for.
 *
 * Ahrefs took `country=pt` and inferred the rest. DataForSEO wants a
 * `location_code` — one of Google's geo target ids — and a `language_code`, and
 * it wants them to agree: asking for Portuguese keyword data in a country where
 * Google Ads has no Portuguese corpus is an error, not an empty list.
 *
 * The mapping is read from the API rather than written down here. The country
 * ids happen to be 2000 plus the ISO 3166 numeric code, which is a tempting
 * thing to hardcode and a bad one — it is a coincidence of Google's numbering,
 * not a contract, and a wrong-but-plausible id targets a real country that
 * isn't the one the project sells in. The endpoint that knows is free.
 */
class DataForSeoLocations
{
    private const string ENDPOINT = '/v3/dataforseo_labs/locations_and_languages';

    private const string CACHE_KEY = 'dataforseo.locations_and_languages';

    public function __construct(private readonly DataForSeoClient $client) {}

    /**
     * The geo target id for a market, e.g. 'PT' → 2620.
     */
    public function codeFor(string $market): int
    {
        $iso = mb_strtoupper(trim($market));
        $known = $this->known();

        if (! isset($known[$iso])) {
            throw new TerminalStepFailure(
                "DataForSEO has no keyword data for market '{$market}'. Set the project's market to a "
                .'country it covers; this will not fix itself on a retry.'
            );
        }

        return $known[$iso]['code'];
    }

    /**
     * The language to ask in, or null to let the search engine pick.
     *
     * Null rather than a guessed default. Sending a language a country has no
     * corpus for is rejected outright, and silently substituting one would be
     * worse than not asking: a project that publishes in English would get its
     * keyword volumes from the Portuguese search that nobody on its site reads.
     * That exact mismatch has already shipped here once.
     */
    public function languageFor(string $market, ?string $locale): ?string
    {
        if ($locale === null || trim($locale) === '') {
            return null;
        }

        // 'pt-PT' and 'en_US' are both locales; DataForSEO wants the language
        // half on its own.
        $language = mb_strtolower((string) preg_replace('/[-_].*$/', '', trim($locale)));

        if ($language === '') {
            return null;
        }

        $iso = mb_strtoupper(trim($market));
        $available = $this->known()[$iso]['languages'] ?? [];

        if (in_array($language, $available, true)) {
            return $language;
        }

        Log::info('DataForSEO has no keyword corpus for this language in this market', [
            'market' => $iso,
            'language' => $language,
            'available' => $available,
        ]);

        return null;
    }

    /**
     * The language a market is answered in when nobody asked for one.
     *
     * Needed because `keyword_overview` requires a language where the expansion
     * endpoints treat it as optional, so "leave it off" is not available there.
     * The country's first listed language rather than a guess: Portugal offers
     * only `pt`, and asking it for `en` is rejected rather than empty.
     */
    public function defaultLanguageFor(string $market): ?string
    {
        return $this->known()[mb_strtoupper(trim($market))]['languages'][0] ?? null;
    }

    /**
     * @return array<string, array{code: int, languages: list<string>}>
     */
    private function known(): array
    {
        $days = max(1, (int) config('research.dataforseo.locations_ttl_days', 30));

        /** @var array<string, array{code: int, languages: list<string>}> $map */
        $map = Cache::remember(self::CACHE_KEY, now()->addDays($days), fn (): array => $this->fetch());

        return $map;
    }

    /**
     * @return array<string, array{code: int, languages: list<string>}>
     */
    private function fetch(): array
    {
        $map = [];

        foreach ($this->client->get(self::ENDPOINT) as $row) {
            $iso = $row['country_iso_code'] ?? null;
            $code = $row['location_code'] ?? null;

            if (! is_string($iso) || $iso === '' || ! is_int($code)) {
                continue;
            }

            $languages = [];

            foreach (is_array($row['available_languages'] ?? null) ? $row['available_languages'] : [] as $language) {
                $tag = is_array($language) ? ($language['language_code'] ?? null) : null;

                if (is_string($tag) && $tag !== '') {
                    $languages[] = mb_strtolower($tag);
                }
            }

            // Labs answers country-level only, so one row per ISO code. Keyed
            // by ISO with the first row winning, rather than assuming that.
            $map[mb_strtoupper($iso)] ??= ['code' => $code, 'languages' => array_values(array_unique($languages))];
        }

        if ($map === []) {
            throw new TerminalStepFailure(
                'DataForSEO returned no locations at all. Nothing can be looked up until that endpoint '
                .'answers, and it is free — so this is about the account, not the request.'
            );
        }

        return $map;
    }
}
