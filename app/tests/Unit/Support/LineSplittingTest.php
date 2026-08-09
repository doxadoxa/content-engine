<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `\R` without `/u` shreds Cyrillic.
 *
 * The most expensive bug this engine has shipped, and the least visible. Every
 * step that reads a model's answer splits it into lines with
 * `preg_split('/\R/', …)`. Without the `/u` modifier PCRE reads the subject as
 * bytes, and in byte mode `\R` matches **0x85** — NEL. 0x85 is also the second
 * byte of Cyrillic `х` (D1 85) and `Ѕ` (D0 85), so every line containing one was
 * split in the middle of a character.
 *
 * What followed made it silent. The piece before the split ended with a
 * dangling lead byte and was therefore invalid UTF-8; the next `/u` regex in the
 * parser returned null for it, `?? ''` turned that into an empty string, and an
 * empty string is dropped. So the model returned a clean outline, the run
 * recorded success, and the article was written from the tail of each heading
 * after the last `х` — "ода бригады", "ности", "ую строительную пыль".
 *
 * Latin is unaffected: no ASCII or Latin-1 letter encodes with 0x85. That is why
 * English and Portuguese articles were perfect for months and the first Russian
 * one came out as nonsense — and why Ukrainian, Bulgarian and Serbian would have
 * done exactly the same.
 */
final class LineSplittingTest extends TestCase
{
    #[Test]
    public function a_cyrillic_line_is_one_line(): void
    {
        // Two `х`, in "подоконниках" and "ухода".
        $heading = 'Пыль на подоконниках и следы затирки остаются после ухода бригады';

        $this->assertSame([$heading], preg_split('/\R/u', $heading));
    }

    #[Test]
    public function the_bug_this_guards_against_is_real(): void
    {
        $heading = 'Пыль на подоконниках и следы затирки остаются после ухода бригады';

        // Byte mode: three pieces where there is one line, and the two the
        // model actually wrote are no longer valid UTF-8.
        /** @var list<string> $shredded */
        $shredded = preg_split('/\R/', $heading) ?: [];

        $this->assertCount(3, $shredded);

        $first = $shredded[0] ?? '';

        $this->assertFalse(mb_check_encoding($first, 'UTF-8'));

        // And this is the step that made it silent rather than loud: a `/u`
        // pattern over invalid UTF-8 returns null, which every caller in the
        // engine coalesced to an empty string and dropped.
        $this->assertNull(preg_replace('/^\s*#+\s*/u', '', $first));
    }

    #[Test]
    public function nothing_in_the_engine_splits_lines_in_byte_mode(): void
    {
        $offenders = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $path => $file) {
            $path = (string) $path;

            if (! is_file($path) || ! str_ends_with($path, '.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            // Plain string work rather than a regex about regexes: the pattern
            // needed to match a pattern here is unreadable enough to be wrong
            // without anybody noticing, which is the failure this file exists
            // to prevent. Find each `'/\R` literal and read the modifiers off
            // the end of it.
            foreach ($this->patternsIn($source, "'/\\R") as $pattern) {
                if (! str_contains($this->modifiersOf($pattern), 'u')) {
                    $offenders[] = basename($path).': '.$pattern;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A line split without `/u` reads its subject as bytes, and `\\R` then matches 0x85 — '
            ."the second byte of Cyrillic `х`. Add the `u` modifier.\n".implode("\n", $offenders),
        );
    }

    /**
     * Every single-quoted PHP literal in `$source` that begins with `$opening`.
     *
     * @return list<string>
     */
    private function patternsIn(string $source, string $opening): array
    {
        $found = [];
        $at = 0;

        while (($start = strpos($source, $opening, $at)) !== false) {
            $end = strpos($source, "'", $start + strlen($opening));
            $at = $start + strlen($opening);

            if ($end === false) {
                continue;
            }

            $found[] = substr($source, $start + 1, $end - $start - 1);
        }

        return $found;
    }

    /** Whatever follows the pattern's closing delimiter. */
    private function modifiersOf(string $pattern): string
    {
        $close = strrpos($pattern, '/');

        return $close === false ? '' : substr($pattern, $close + 1);
    }
}
