<?php

declare(strict_types=1);

namespace App\Visibility;

/**
 * Did the assistant mention us?
 *
 * Deliberately deterministic, and deliberately not a model call. This number is
 * the headline on a dashboard and the thing a trend line is drawn through; if a
 * model decided it, two runs over identical answers could disagree, and nobody
 * could tell a real change in visibility from the judge having an off day.
 *
 * Two ways to count as mentioned, because assistants do both: naming the brand
 * in the prose, or citing its website without naming it. The second still sends
 * the customer.
 */
final class BrandPresence
{
    /**
     * @param  list<array{url: string, title: string}>  $citations
     */
    public static function found(string $text, array $citations, string $brand, ?string $siteUrl = null): bool
    {
        return self::namesBrand($text, $brand) || self::citesSite($citations, $siteUrl);
    }

    /**
     * The brand's name, as a whole word or run of words.
     *
     * Whole-word because a brand called "Point" would otherwise be found in
     * "appointment" and score 100% forever. Flexible about the space between
     * words because "Cleaning Point", "CleaningPoint" and "cleaning-point" are
     * one brand and three spellings, and assistants use all three.
     */
    public static function namesBrand(string $text, string $brand): bool
    {
        $brand = trim($brand);

        if ($brand === '') {
            return false;
        }

        // A brand that is only punctuation splits to nothing, and a pattern
        // built from no words matches everywhere.
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($brand), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ));

        if ($words === []) {
            return false;
        }

        // A single-word brand under three characters is not searchable prose —
        // "CP" appears in any answer long enough. Better to report not-found
        // than to report a number that is always 100.
        if (count($words) === 1 && mb_strlen($words[0]) < 3) {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])'
            .implode('[^\p{L}\p{N}]{0,2}', array_map(
                static fn (string $word): string => preg_quote($word, '/'),
                $words,
            ))
            .'(?![\p{L}\p{N}])/ui';

        return preg_match($pattern, $text) === 1;
    }

    /**
     * @param  list<array{url: string, title: string}>  $citations
     */
    public static function citesSite(array $citations, ?string $siteUrl): bool
    {
        $host = self::host($siteUrl);

        if ($host === null) {
            return false;
        }

        foreach ($citations as $citation) {
            $cited = self::host($citation['url']);

            // Suffix rather than equality, so a citation of
            // `blog.example.com` counts for a project whose site is
            // `example.com`. Anchored on a dot so `notexample.com` does not.
            if ($cited === $host || ($cited !== null && str_ends_with($cited, '.'.$host))) {
                return true;
            }
        }

        return false;
    }

    private static function host(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = str_contains($url, '//') ? $url : 'https://'.$url;
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return (string) preg_replace('/^www\./', '', mb_strtolower($host));
    }
}
