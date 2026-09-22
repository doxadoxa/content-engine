<?php

declare(strict_types=1);

namespace App\Pages;

use App\Models\PageSnapshot;
use App\Models\SitePage;

/** Resolve only tenant-scoped identities supported by a captured canonical declaration. */
final class TrackedPageIdentity
{
    public function find(string $url): ?SitePage
    {
        $url = PageUrl::normalize($url);
        $page = SitePage::query()->tracked()->where('canonical_hash', hash('sha256', $url))->first();
        if ($page !== null) {
            return $page;
        }
        $candidates = PageSnapshot::query()->where('source_kind', 'public')
            ->where(fn ($query) => $query->where('source_url', $url)->orWhere('metadata->requested_url', $url))
            ->with('page')->orderByDesc('id')->cursor();
        foreach ($candidates as $snapshot) {
            $page = $snapshot->page;
            if ($page === null || $page->tracked_at === null
                || ($snapshot->metadata['canonical_url'] ?? null) !== $page->canonical_url
                || ($snapshot->metadata['locale'] ?? null) !== $page->locale) {
                continue;
            }
            $latest = $page->snapshots()->where('source_kind', 'public')->first();
            if ($latest?->id === $snapshot->id) {
                return $page;
            }
        }

        return null;
    }
}
