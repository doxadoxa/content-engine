<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\Signal;
use Illuminate\Support\Str;
use Normalizer;

/**
 * What a project is about, in its own words (§4.1, §4.3).
 *
 * §4.1 says the listening contour runs `keyword_search` "по сущностям проекта",
 * and §4.3 makes resolving into that same vocabulary a hard gate on a native
 * post. Both sentences need one answer to "what are this project's entities",
 * and it has to be derived rather than configured: no operator maintains an
 * entity list, and the four projects this engine runs have none.
 *
 * So it is read off the work the project has already done — the entities the
 * research and generation pipelines already store on every unit, the clusters
 * those units are filed under, and the competitor names in the Brand Brief.
 * That has a property a hand-written list would not: it grows with the corpus,
 * which is exactly the "два ребёнка одного кластера сущностей" of §1.3.
 *
 * **Terms are capped, and the cap is the budget.** §2 allows 2 200 search
 * requests per user per 24 h and §4.1 runs hourly in two modes, so an uncapped
 * vocabulary is an outage waiting for the project whose corpus grows past
 * forty-five entities. {@see MAX_TERMS} × 2 modes × 24 hours is the arithmetic,
 * and it leaves the rest of the window for the other contours.
 *
 * Nothing here calls a model. A vocabulary assembled from columns the engine
 * already wrote is free, deterministic and reproducible in a test; asking a
 * model what a project is about every hour is none of those.
 */
final class ProjectVocabulary
{
    /**
     * How many terms one hourly run searches for.
     *
     * Eight terms in two modes is sixteen requests an hour, 384 a day against
     * the 2 200 of §2 — room for the engage contour, the insights reads and a
     * day when the hourly run is behind and catches up.
     */
    public const int MAX_TERMS = 8;

    /**
     * Shorter than this and a term matches everything.
     *
     * A two-letter entity in a keyword search is not listening, it is a sample
     * of the platform, and every post it returns costs a model call to classify.
     */
    private const int MIN_TERM_LENGTH = 3;

    /** How many entities to consider before ranking. Bounds the query, not the corpus. */
    private const int MAX_VOCABULARY = 200;

    /**
     * The vocabulary a signal's entities are resolved into.
     *
     * Ordered longest first, which matters at the resolution step below: for a
     * project holding both "hard water" and "water", a text mentioning the
     * first should resolve to the first and not have both counted as separate
     * subjects — the fingerprint of §4.1 is keyed on the entity set, so a
     * spurious extra entity is a subject that escapes the dedup window.
     *
     * @return list<string>
     */
    public function entities(Project $project): array
    {
        // Cast, because PHP turns a numeric-looking array key into an int and a
        // project's entity list holds model numbers, years and plan tiers. The
        // same trap `Signal::fingerprintFor()` documents for sorting, one layer
        // earlier.
        $entities = array_map(strval(...), array_keys($this->counted($project)));

        usort($entities, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($entities, 0, self::MAX_VOCABULARY);
    }

    /**
     * What this hour actually searches for, most-used first.
     *
     * Frequency across the corpus rather than recency or alphabet: the entity
     * that appears on twenty units is what the project is about, and the one
     * that appears on one is a detail of a single article. With a cap this
     * tight, spending a request on the detail instead of the subject is the
     * difference between listening to an industry and listening to a footnote.
     *
     * @return list<string>
     */
    public function terms(Project $project): array
    {
        $counted = $this->counted($project);

        $terms = array_map(strval(...), array_keys($counted));

        // Frequency desc, then the term itself, so a project whose entities all
        // appear once still searches the same eight every hour instead of
        // whatever order Postgres happened to return. An hourly job whose query
        // set shuffles cannot be reasoned about from the logs.
        usort($terms, static function (string $a, string $b) use ($counted): int {
            return [$counted[$b], $a] <=> [$counted[$a], $b];
        });

        return array_slice($terms, 0, self::MAX_TERMS);
    }

    /**
     * Which of the project's entities this text is actually about.
     *
     * A containment test on a normalised copy of both sides, and deliberately
     * nothing cleverer. §4.3 uses the resolved set as a gate and §4.1 uses it
     * as half of the fingerprint, so the property that matters is that the same
     * text resolves to the same set every time — a nearest-neighbour search
     * over embeddings would be better at recall and worse at that, and it would
     * put a paid call on the hourly intake path for every post read.
     *
     * Longer entities win: once "hard water" has matched, "water" is not
     * counted again, because the two are one subject and the fingerprint of
     * §4.1 is keyed on the set.
     *
     * @param  list<string>  $vocabulary  from {@see entities()}, longest first
     * @return list<string>
     */
    public static function resolve(string $text, array $vocabulary): array
    {
        $haystack = self::normalise($text);

        if ($haystack === '') {
            return [];
        }

        $resolved = [];
        $consumed = $haystack;

        foreach ($vocabulary as $entity) {
            $needle = self::normalise($entity);

            if ($needle === '' || ! str_contains($haystack, $needle)) {
                continue;
            }

            // Against the copy the longer matches have already been cut out of,
            // so "water" does not match the "water" inside "hard water".
            if (! str_contains($consumed, $needle)) {
                continue;
            }

            $consumed = str_replace($needle, ' ', $consumed);
            $resolved[] = $entity;
        }

        return $resolved;
    }

    /**
     * Every candidate term with how often the corpus uses it.
     *
     * @return array<string, int>
     */
    private function counted(Project $project): array
    {
        $counted = [];

        $units = ContentItem::query()
            ->select(['entities', 'cluster'])
            ->get();

        foreach ($units as $unit) {
            foreach ($unit->entities as $entity) {
                $this->count($counted, (string) $entity);
            }

            $this->count($counted, (string) ($unit->cluster ?? ''));
        }

        // The competitor list, which is the one part of the Brand Brief that is
        // already a list of names rather than prose. §4.1's contour is about
        // "чужие разговоры", and a conversation about a competitor is one of
        // the few reliably on-topic ones the platform will hand us.
        $brief = BrandBrief::activeFor($project);

        if ($brief !== null) {
            foreach ($brief->competitors as $competitor) {
                $this->count($counted, (string) $competitor);
            }
        }

        return $counted;
    }

    /**
     * @param  array<string, int>  $counted
     */
    private function count(array &$counted, string $term): void
    {
        $term = Str::squish($term);

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return;
        }

        $counted[$term] = ($counted[$term] ?? 0) + 1;
    }

    /**
     * Lowercased, punctuation flattened to spaces, NFC-composed.
     *
     * The same normalisation {@see Signal::fingerprintFor()} uses,
     * and for the same reason its docblock gives: a combining acute is its own
     * codepoint in NFD, so the decomposed "água" and the composed one are two
     * different strings, and this engine runs Portuguese projects where that is
     * most of the vocabulary.
     */
    private static function normalise(string $value): string
    {
        if (! Normalizer::isNormalized($value, Normalizer::FORM_C)) {
            $composed = Normalizer::normalize($value, Normalizer::FORM_C);

            if (is_string($composed)) {
                $value = $composed;
            }
        }

        return ' '.Str::squish((string) preg_replace(
            '/[^\p{L}\p{N}]+/u',
            ' ',
            mb_strtolower($value),
        )).' ';
    }
}
