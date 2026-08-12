<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\PageCheck;
use App\Audit\PageFacts;
use App\Enums\AuditCheckGroup;

/**
 * Can this page be indexed at all?
 *
 * The check that has to run before the rest mean anything. A page in the
 * sitemap that answers 404, or that carries `noindex`, is not a page with a
 * short meta description — it is a page nothing will ever read, and the twelve
 * findings the other checks would raise about it are all downstream of this
 * one. It is deliberately the only check that fires on an unreadable page; the
 * inspection step skips the others, so a dead URL produces one clear finding
 * rather than a column of noise.
 */
class IndexabilityCheck implements PageCheck
{
    public static function key(): string
    {
        return 'indexability';
    }

    public function label(): string
    {
        return 'Indexability';
    }

    public function description(): string
    {
        return 'Checks that pages listed in the sitemap actually load and do not ask to be excluded.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Seo;
    }

    /** @return list<CheckFinding> */
    public function run(PageFacts $page): array
    {
        if ($page->statusCode === null) {
            return [CheckFinding::high(
                'This page is in the sitemap but could not be reached at all.',
            )];
        }

        if ($page->statusCode >= 400) {
            return [CheckFinding::high(
                "This page is in the sitemap and answers {$page->statusCode}.",
                ['status' => $page->statusCode],
            )];
        }

        if ($page->statusCode >= 300) {
            return [CheckFinding::medium(
                "This page is in the sitemap and redirects ({$page->statusCode}); the sitemap should name the destination.",
                ['status' => $page->statusCode],
            )];
        }

        if ($page->isNoIndex) {
            return [CheckFinding::high(
                'This page asks search engines not to index it, but the sitemap invites them to.',
            )];
        }

        return [];
    }
}
