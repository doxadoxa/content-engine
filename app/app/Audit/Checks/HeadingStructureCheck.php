<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\PageCheck;
use App\Audit\PageFacts;
use App\Enums\AuditCheckGroup;

/**
 * One h1, and no gaps on the way down.
 *
 * Filed under GEO rather than SEO deliberately. Search engines have long since
 * stopped needing the outline; assistants have not — a passage is quoted with
 * the heading above it as its context, and a page whose structure jumps from h1
 * to h4 hands back sections nothing can place. §9.3's whole argument is that
 * being quotable is a separate property from being rankable, and this is one of
 * the few places the two genuinely diverge.
 */
class HeadingStructureCheck implements PageCheck
{
    public static function key(): string
    {
        return 'heading_structure';
    }

    public function label(): string
    {
        return 'Heading structure';
    }

    public function description(): string
    {
        return 'Checks that the page has exactly one h1 and an outline an assistant can follow.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Geo;
    }

    /** @return list<CheckFinding> */
    public function run(PageFacts $page): array
    {
        $findings = [];

        $h1 = $page->headingsAtLevel(1);

        if ($h1 === []) {
            $findings[] = CheckFinding::medium('This page has no h1, so nothing states what it is about.');
        } elseif (count($h1) > 1) {
            $findings[] = CheckFinding::low(
                'This page has '.count($h1).' h1 headings, so its subject is ambiguous.',
                ['headings' => array_map(static fn (array $h): string => $h['text'], $h1)],
            );
        }

        $skipped = $this->firstSkip($page);

        if ($skipped !== null) {
            $findings[] = CheckFinding::low(
                "The outline jumps from h{$skipped['from']} to h{$skipped['to']}, leaving a section with no parent.",
                $skipped,
            );
        }

        return $findings;
    }

    /**
     * The first place the outline gains more than one level at once.
     *
     * Only the first: a page with a broken pattern breaks it in every section,
     * and eight identical findings about one mistake is a screen nobody reads.
     *
     * @return array{from: int, to: int, heading: string}|null
     */
    private function firstSkip(PageFacts $page): ?array
    {
        $previous = null;

        foreach ($page->headings as $heading) {
            $level = $heading['level'];

            if ($previous !== null && $level > $previous + 1) {
                return ['from' => $previous, 'to' => $level, 'heading' => $heading['text']];
            }

            $previous = $level;
        }

        return null;
    }
}
