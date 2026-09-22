<?php

declare(strict_types=1);

namespace App\Visibility\Accuracy;

use App\Models\AiAccuracyAssessment;
use App\Models\AiAccuracyFinding;
use App\Models\PageSnapshot;
use App\Models\SitePage;

final class PageCorrectionSupport
{
    public static function current(?SitePage $page, AiAccuracyAssessment $assessment): bool
    {
        $snapshot = $page?->latestSnapshot;

        return $page?->tracked_at !== null && $snapshot?->id === $assessment->snapshot_id
            && $snapshot->source_kind === 'public' && $snapshot->captured_at->greaterThanOrEqualTo(now()->subDays(30))
            && ($snapshot->metadata['canonical_url'] ?? null) === $page->canonical_url
            && ($snapshot->metadata['locale'] ?? null) === $page->locale;
    }

    public static function editable(PageSnapshot $snapshot, AiAccuracyFinding $finding): bool
    {
        $quote = $finding->evidence['exactQuote'];
        if (mb_strlen($quote) > 2400) {
            return false;
        }
        /** @var list<array<string,mixed>> $surfaces */
        $surfaces = $snapshot->metadata['fact_surfaces']['surfaces'] ?? [];
        $surface = collect($surfaces)->firstWhere('id', $finding->evidence['sectionKey']);
        $field = match ($surface['kind'] ?? null) {
            'title' => 'title', 'meta_description' => 'description', 'delivered_body_text' => 'body_text', default => null,
        };

        return $field !== null && str_contains($snapshot->fields[$field] ?? '', $quote);
    }
}
