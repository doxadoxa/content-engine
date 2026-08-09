<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Pipelines\Steps\Generation\CoverEntities;
use Illuminate\Support\Str;

/**
 * `Str::squish()`, minus the null.
 *
 * Laravel types that helper as returning `string` and it does not: it is two
 * `preg_replace` calls with `/u` patterns, and `preg_replace` returns **null**
 * rather than its subject when a match fails. Two ways in, and this project
 * meets both:
 *
 * - invalid UTF-8, which model output and scraped pages both produce; and
 * - PCRE's backtrack limit, which a long block of non-Latin text reaches sooner
 *   than a Latin one because it is more bytes for the same number of
 *   characters.
 *
 * Every article this engine wrote was Latin until the project added Russian.
 * The first Cyrillic draft killed `build_geo_layer` on
 * `str_starts_with(): Argument #1 ($haystack) must be of type string, null
 * given` — a finished, paid-for article lost to a helper that lies about its
 * return type. That site was patched where it stood, which left the same crash
 * waiting at every other call: {@see CoverEntities}
 * squishes the entire article body and hands it to `str_contains`, on the
 * largest input in the pipeline.
 *
 * So it is fixed once, here, and called everywhere instead. Unreadable text
 * squishes to nothing, which is the right answer for every caller: they are all
 * asking "what does this say", and the answer for a string that cannot be read
 * is "nothing" — a heading that gets skipped, an entity that reads as
 * uncovered. None of them is improved by an exception.
 */
final class Squish
{
    public static function text(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Annotated because the framework's own signature is the thing that is
        // wrong here; static analysis believes it and would call the coalesce
        // dead code.
        /** @var string|null $squished */
        $squished = Str::squish($value);

        return $squished ?? '';
    }
}
