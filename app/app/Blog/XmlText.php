<?php

declare(strict_types=1);

namespace App\Blog;

/**
 * Text made fit for an XML 1.0 document.
 *
 * Escaping is not enough for XML the way it is for HTML: a control character
 * (anything below U+0020 but tab, newline and carriage return) is illegal
 * even as `&#1;`, and one stray byte in one article makes the whole feed or
 * sitemap unparseable for every reader. The article text is the engine's,
 * written by a model, so it is cleaned on the way out rather than trusted.
 */
final class XmlText
{
    public static function clean(?string $text): string
    {
        // Invalid UTF-8 first, or the /u pattern below fails on the whole string.
        $text = mb_scrub((string) $text, 'UTF-8');

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }
}
