<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\PageCheck;
use App\Audit\PageFacts;
use App\Enums\AuditCheckGroup;

/**
 * The canonical link: present, absolute, and pointing at this page.
 *
 * A canonical pointing somewhere else is the highest-consequence fault a single
 * tag can carry — the page is telling every index to credit a different URL,
 * and the symptom is an article that was published, is live, reads correctly
 * and never appears anywhere. That is precisely the failure this whole section
 * exists to make visible, so it is the one comparison worth doing carefully:
 * the query string and a trailing slash are ignored, because a canonical
 * differing only in those is a normalisation choice rather than a mistake.
 */
class CanonicalUrlCheck implements PageCheck
{
    public static function key(): string
    {
        return 'canonical_url';
    }

    public function label(): string
    {
        return 'Canonical URL';
    }

    public function description(): string
    {
        return 'Validates that the canonical tag is present, absolute, and points at the page itself.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Seo;
    }

    /** @return list<CheckFinding> */
    public function run(PageFacts $page): array
    {
        $canonical = trim((string) $page->canonical);

        if ($canonical === '') {
            return [CheckFinding::medium(
                'This page has no canonical tag, so a duplicate of it could outrank it.',
            )];
        }

        if (! str_starts_with($canonical, 'http://') && ! str_starts_with($canonical, 'https://')) {
            return [CheckFinding::medium(
                'The canonical tag is a relative URL, which not every crawler resolves the same way.',
                ['canonical' => $canonical],
            )];
        }

        if (! $this->sameDocument($canonical, $page->url)) {
            return [CheckFinding::high(
                'The canonical tag points at a different page, so this one asks not to be indexed.',
                ['canonical' => $canonical, 'page' => $page->url],
            )];
        }

        return [];
    }

    /**
     * Two URLs addressing the same document.
     *
     * Scheme, host and path only, with the path's trailing slash and case-
     * insensitive host normalised away. A canonical that differs by `?utm=…` or
     * by a trailing slash is a site being tidy, not a site misdirecting itself,
     * and flagging those would bury the one case that matters.
     */
    private function sameDocument(string $canonical, string $url): bool
    {
        return $this->identity($canonical) === $this->identity($url);
    }

    private function identity(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        $path = rtrim((string) ($parts['path'] ?? '/'), '/');

        return mb_strtolower((string) ($parts['scheme'] ?? 'https'))
            .'://'.mb_strtolower((string) $parts['host'])
            .($path === '' ? '/' : $path);
    }
}
