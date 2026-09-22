<?php

declare(strict_types=1);

namespace App\Opportunities;

use App\Models\SitePage;

/** Discovery candidates are never silently promoted into verified link targets. */
final class ExistingPageOverlap
{
    public function __construct(private readonly OpportunityText $text) {}

    /** @return list<array{id: string, title: string, url: string, similarity: float, tracked: bool, locale_evidence: string}> */
    public function forPage(SitePage $page): array
    {
        $similar = [];
        $language = strtolower(explode('-', str_replace('_', '-', $page->locale ?? ''))[0]);
        foreach (SitePage::query()->whereKeyNot($page->id)->orderBy('url')->limit(500)->get() as $other) {
            $prefix = strtolower(explode('/', trim((string) parse_url($other->url, PHP_URL_PATH), '/'))[0]);
            $sameLocale = $other->locale !== null ? $other->locale === $page->locale : ($language !== '' && $prefix === $language);
            if (! $sameLocale) {
                continue;
            }
            $score = $this->text->overlap($page->title, $other->title);
            if ($score >= 0.4) {
                $similar[] = ['id' => $other->id, 'url' => $other->canonical_url ?? $other->url, 'title' => $other->title, 'similarity' => round($score, 2), 'tracked' => $other->tracked_at !== null, 'locale_evidence' => $other->locale !== null ? 'tracked_identity' : 'url_prefix_needs_confirmation'];
            }
        }
        usort($similar, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity'] ?: strcmp($a['url'], $b['url']));

        return array_slice($similar, 0, 5);
    }
}
