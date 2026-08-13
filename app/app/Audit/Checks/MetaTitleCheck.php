<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\PageCheck;
use App\Audit\PageFacts;
use App\Enums\AuditCheckGroup;

/**
 * The title tag: present, and the right length to survive a results page.
 *
 * The bounds are about what gets *shown*, not about a ranking rule. Google
 * truncates around 60 characters and pads short titles with the site name, so
 * both ends of the range are "the searcher will not read what you wrote".
 */
class MetaTitleCheck implements PageCheck
{
    private const int TOO_SHORT = 15;

    private const int TOO_LONG = 60;

    public static function key(): string
    {
        return 'meta_title';
    }

    public function label(): string
    {
        return 'Meta title';
    }

    public function description(): string
    {
        return 'Validates that every page has a title tag of a length that survives a results page.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Seo;
    }

    /** @return list<CheckFinding> */
    public function run(PageFacts $page): array
    {
        $title = trim((string) $page->title);

        if ($title === '') {
            return [CheckFinding::high('This page has no title tag.')];
        }

        $length = mb_strlen($title);

        if ($length > self::TOO_LONG) {
            return [CheckFinding::low(
                "The title is {$length} characters and will be cut short in results.",
                ['length' => $length, 'limit' => self::TOO_LONG, 'title' => $title],
            )];
        }

        if ($length < self::TOO_SHORT) {
            return [CheckFinding::low(
                "The title is only {$length} characters, so search engines will pad it with something of their own.",
                ['length' => $length, 'minimum' => self::TOO_SHORT, 'title' => $title],
            )];
        }

        return [];
    }
}
