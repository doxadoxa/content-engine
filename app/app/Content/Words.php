<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Counting words in a language that is not English.
 *
 * `str_word_count()` is ASCII-minded: its idea of a letter is a–z plus a
 * locale's high-byte range, so it reads "limpeza pós-obra" as four words and a
 * Cyrillic sentence as none at all. That last one is not a rounding error. A
 * 1,847-word Russian article was reported as 66 words, and the rhythm check —
 * which measures how much sentence length varies — saw every sentence as empty,
 * found fewer than eight of them, and failed the article for uniformity it
 * could not see.
 *
 * The same bug has now been fixed three times in this codebase in three
 * different files, which is the argument for it living in one.
 *
 * Apostrophes and hyphens stay inside a word, matching what `str_word_count`
 * intends: "doesn't" is one word and so is "post-renovation".
 */
final class Words
{
    /**
     * What counts as being inside a word: any letter or digit in any script,
     * plus the two marks that join one.
     */
    private const string PATTERN = "/[^\p{L}\p{N}'’\-]+/u";

    public static function count(string $text): int
    {
        return count(self::all($text));
    }

    /**
     * @return list<string>
     */
    public static function all(string $text): array
    {
        $words = preg_split(self::PATTERN, $text, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($words)) {
            // preg_split returns false on invalid UTF-8 or a backtrack limit.
            // Zero words is the honest answer for text we could not read, and
            // it is what every caller here already handles.
            return [];
        }

        // A lone hyphen or apostrophe is punctuation that survived the split,
        // not a word — an em-dash used as a bullet would otherwise count.
        return array_values(array_filter(
            $words,
            static fn (string $word): bool => preg_match('/[\p{L}\p{N}]/u', $word) === 1,
        ));
    }
}
