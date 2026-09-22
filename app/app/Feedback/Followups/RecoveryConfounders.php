<?php

declare(strict_types=1);

namespace App\Feedback\Followups;

use App\Models\PagePublication;
use App\Models\PagePublicationOperation;
use Carbon\CarbonImmutable;

final class RecoveryConfounders
{
    /** @return list<array<string,mixed>> */
    public function within(string $pageId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $operations = PagePublicationOperation::query()->where('site_page_id', $pageId)->where('kind', 'recovery')
            ->where(fn ($query) => $query
                ->where(fn ($done) => $done->where('committed_at', '>=', $from->utc())->where('committed_at', '<', $to->utc()))
                ->orWhere(fn ($unknown) => $unknown->whereNull('committed_at')->whereNotNull('dispatch_started_at')->where('dispatch_started_at', '<', $to->utc())->whereIn('status', ['sending', 'outcome_unknown', 'blocked_unresolved'])))
            ->orderBy('id')->get();
        $records = $operations->map(fn (PagePublicationOperation $operation): array => ['operation_id' => $operation->id, 'publication_id' => $operation->publication_id,
            'at' => ($operation->committed_at ?? $operation->dispatch_started_at)?->toIso8601String(), 'status' => $operation->committed_at === null ? 'outcome_unknown' : 'recovered'])->all();
        foreach (PagePublication::query()->where('site_page_id', $pageId)->where('recovered_at', '>=', $from->utc())->where('recovered_at', '<', $to->utc())
            ->whereNotIn('id', $operations->pluck('publication_id'))->orderBy('id')->get() as $publication) {
            $records[] = ['operation_id' => null, 'publication_id' => $publication->id, 'at' => $publication->recovered_at?->toIso8601String(), 'status' => 'recovered'];
        }

        return array_values($records);
    }
}
