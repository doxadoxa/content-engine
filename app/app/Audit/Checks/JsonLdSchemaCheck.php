<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\PageCheck;
use App\Audit\PageFacts;
use App\Enums\AuditCheckGroup;

/**
 * Structured data: present, and parsing.
 *
 * The engine writes FAQ and HowTo blocks into its own articles (§9.3), so this
 * is partly the audit checking our own work — and partly the reason it has to
 * cover the whole site rather than only what we published: an assistant reading
 * the brand forms its picture from the pages that were already there.
 *
 * A block that is present and does not parse is worse than none and is reported
 * separately. Invalid JSON-LD is silently discarded by every consumer, so the
 * site looks marked up to the person who wrote it and blank to everything else.
 */
class JsonLdSchemaCheck implements PageCheck
{
    public static function key(): string
    {
        return 'json_ld_schema';
    }

    public function label(): string
    {
        return 'JSON-LD schema';
    }

    public function description(): string
    {
        return 'Validates that structured data is present and parses, so assistants can read the page as data.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Geo;
    }

    /** @return list<CheckFinding> */
    public function run(PageFacts $page): array
    {
        if ($page->hasBrokenJsonLd) {
            return [CheckFinding::medium(
                'This page has a JSON-LD block that does not parse, so every reader discards it.',
                ['types' => $page->jsonLdTypes],
            )];
        }

        if ($page->jsonLdTypes === []) {
            return [CheckFinding::medium(
                'This page has no structured data, so assistants have to infer what it is.',
            )];
        }

        return [];
    }
}
