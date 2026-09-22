<?php

declare(strict_types=1);

namespace App\Feedback\Followups;

use App\Feedback\Measurements\ReadStatus;
use App\Models\MeasurementRead;
use App\Models\PageMetric;
use Carbon\CarbonImmutable;

/** Copies the observed rows so later Google restatements cannot rewrite a pinned baseline. */
final class SearchWindowEvidence
{
    /** @param list<string> $pageIds
     * @return array<string, mixed>
     */
    public function capture(array $pageIds, ObservationWindow $window, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $reads = MeasurementRead::query()->where('source', 'gsc_pages')
            ->where('window_from', '<=', $window->from->toDateString())->where('window_to', '>=', $window->to->toDateString())
            ->where('finished_at', '<=', $asOf)->whereJsonContains('metadata->page_ids', $pageIds)->orderByDesc('id');
        $latest = $pageIds === [] ? null : $reads->first();
        $successful = $pageIds === [] ? null : $reads->clone()->where('status', ReadStatus::Complete)->first();
        $validReads = MeasurementRead::query()->where('source', 'gsc_pages')->where('status', ReadStatus::Complete)->where('finished_at', '<=', $asOf)->select('id');
        $rows = PageMetric::query()->whereIn('site_page_id', $pageIds)->whereIn('measurement_read_id', $validReads)
            ->whereBetween('measured_on', [$window->from->toDateString(), $window->to->toDateString()])->orderBy('measured_on')->orderBy('site_page_id')->get();
        $properties = MeasurementRead::query()->whereIn('id', $rows->pluck('measurement_read_id')->unique())->get()
            ->map(static fn (MeasurementRead $read): mixed => $read->metadata['property'] ?? null)->unique()->values()->all();
        $property = count($properties) === 1 && is_string($properties[0]) ? $properties[0] : null;
        $impressions = $rows->isEmpty() ? null : (int) $rows->sum('impressions');
        $clicks = $rows->isEmpty() ? null : (int) $rows->sum('clicks');
        $positionRows = $rows->whereNotNull('position_tenths');
        $positionWeight = (int) $positionRows->sum('impressions');
        $status = $latest?->status === ReadStatus::Complete && $successful !== null ? 'recorded' : ($latest?->status->value ?? 'unavailable');
        if ($rows->isEmpty() && $status === 'recorded') {
            $status = 'unavailable';
        }

        return [
            ...$window->toArray(), 'status' => $status,
            'reason' => $latest->reason ?? ($rows->isEmpty() ? 'No page observations are available for this window.' : null),
            'impressions' => $impressions, 'clicks' => $clicks,
            'ctr' => $impressions !== null && $impressions > 0 ? $clicks / $impressions : null,
            'position' => $positionWeight > 0 ? $positionRows->sum(static fn (PageMetric $row): int => $row->position_tenths * $row->impressions) / $positionWeight / 10 : null,
            'observed_days' => $rows->map(static fn (PageMetric $row): string => $row->measured_on->toDateString())->unique()->count(),
            'observed_page_days' => $rows->count(), 'expected_page_days' => count($pageIds) * $window->days,
            'sparse' => $rows->count() < count($pageIds) * $window->days,
            'window_covered' => $successful !== null, 'last_read_id' => $latest?->id,
            'last_successful_read_id' => $successful?->id, 'last_successful_at' => $successful?->finished_at?->toIso8601String(),
            'read_metadata' => $latest->metadata ?? [], 'property' => $property,
            'property_consistent' => $property !== null && ($latest->metadata['property'] ?? null) === $property,
            'rows' => $rows->map(static fn (PageMetric $row): array => [
                'page_id' => $row->site_page_id, 'day' => $row->measured_on->toDateString(),
                'impressions' => $row->impressions, 'clicks' => $row->clicks, 'position_tenths' => $row->position_tenths,
                'measurement_read_id' => $row->measurement_read_id,
            ])->all(),
            'limitations' => ['Observed Search Console rows are not a traffic census. Missing days and private queries are not zero.', 'A before/after association does not establish an effect caused by this change.'],
        ];
    }
}
