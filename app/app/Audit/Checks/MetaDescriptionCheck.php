<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\PageCheck;
use App\Audit\PageFacts;
use App\Enums\AuditCheckGroup;

/**
 * The meta description: the sentence that decides whether a result is clicked.
 *
 * Medium when missing rather than high — the page is still indexed, and search
 * engines will assemble something from the body. What is lost is the one line
 * of copy the site controls between the searcher and the page.
 */
class MetaDescriptionCheck implements PageCheck
{
    private const int TOO_SHORT = 70;

    private const int TOO_LONG = 160;

    public static function key(): string
    {
        return 'meta_description';
    }

    public function label(): string
    {
        return 'Meta description';
    }

    public function description(): string
    {
        return 'Checks that every page has a meta description long enough to be worth showing.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Seo;
    }

    /** @return list<CheckFinding> */
    public function run(PageFacts $page): array
    {
        $description = trim((string) $page->description);

        if ($description === '') {
            return [CheckFinding::medium('This page has no meta description.')];
        }

        $length = mb_strlen($description);

        if ($length > self::TOO_LONG) {
            return [CheckFinding::low(
                "The description is {$length} characters and will be truncated.",
                ['length' => $length, 'limit' => self::TOO_LONG],
            )];
        }

        if ($length < self::TOO_SHORT) {
            return [CheckFinding::low(
                "The description is only {$length} characters, which wastes most of the space available.",
                ['length' => $length, 'minimum' => self::TOO_SHORT],
            )];
        }

        return [];
    }
}
