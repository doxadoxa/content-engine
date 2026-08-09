<?php

declare(strict_types=1);

namespace App\Research\Contracts;

use App\Research\KeywordIdea;

/**
 * Where keyword data comes from (§4.1: Ahrefs, behind an interface with a fake).
 *
 * The same arrangement as ModelGateway and for the same reason: one door, so
 * the suite can bind a fake behind it and never reach the network, and so the
 * step that uses keyword data does not know which vendor produced it.
 */
interface KeywordSource
{
    /** A short name, recorded on what it produces. */
    public function name(): string;

    /** Whether it is configured well enough to be called. */
    public function isConfigured(): bool;

    /**
     * Keywords related to a seed, best first.
     *
     * `$language` is the locale the project publishes in, and it is separate
     * from the market on purpose: a business selling in Portugal to English
     * speakers wants Portuguese search volumes for English queries. Ahrefs
     * inferred one from the other and could not express that; asking a market
     * for its default language is how a project that chose English ends up
     * planning a month of Portuguese.
     *
     * Optional because a source that cannot honour it should say so by
     * ignoring it, not by making every caller pretend to care.
     *
     * @param  string  $market  ISO country code the volumes are for
     * @param  string|null  $language  locale or language tag, e.g. 'en' or 'pt-PT'
     * @return list<KeywordIdea>
     */
    public function matchingTerms(string $seed, string $market, int $limit = 50, ?string $language = null): array;

    /**
     * The language this source can actually answer about a market in.
     *
     * Not the same question as "what language does the project publish in", and
     * conflating them produced nothing at all on the first live run: sixty
     * perfectly good English topics proposed for a Portuguese market, of which
     * the vendor recognised zero, because DataForSEO lists Portugal's available
     * languages as `pt` alone.
     *
     * `$preferred` is honoured when the market has data in it. Null means the
     * source has no opinion and the caller should use its own.
     */
    public function languageFor(string $market, ?string $preferred = null): ?string;

    /**
     * Volume and difficulty for phrases we already have.
     *
     * The other direction from {@see matchingTerms()}, and the reason both
     * exist. Expansion answers "what else contains this phrase", which can only
     * ever return rewordings of what it was given: seeded with "cleaning
     * lisbon" it returns "lisbon cleaning", and a month planned from it is one
     * idea nine times.
     *
     * This one is asked about phrases nothing suggested — a stain, a customer
     * segment, a question about how the service works — and says whether anyone
     * searches for them. Propose, then check, rather than expand, then filter.
     *
     * A phrase the vendor has no data for is left out of the result rather than
     * returned as zero: "nobody searches this" and "we have never seen this"
     * are different facts, and only the caller knows which one is disqualifying.
     *
     * @param  list<string>  $keywords  exact phrases, not seeds
     * @return list<KeywordIdea> in the order the vendor answered, unknowns omitted
     */
    public function measure(array $keywords, string $market, ?string $language = null): array;

    /**
     * Pages that actually rank for a query, right now.
     *
     * The only source of real URLs this system has. A model with no web access
     * answers "cite authoritative sources" by remembering them, which is a
     * polite way of saying it guesses — and a 404 in a published article is a
     * claim of sourcing that falls over the moment anybody checks.
     *
     * @param  string|null  $language  locale or language tag; see matchingTerms()
     * @return list<array{url: string, title: string}>
     */
    public function rankingPages(string $keyword, string $market, int $limit = 10, ?string $language = null): array;
}
