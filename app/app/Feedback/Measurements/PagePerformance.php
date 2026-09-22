<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Feedback\Followups\ChangeFollowupReport;
use App\Models\MeasurementRead;
use App\Models\PageMetric;
use App\Models\PageQueryMetric;
use App\Models\Project;
use App\Models\PropertyAnalyticsMetric;
use App\Models\SitePage;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Read model for observed data. Sparse or missing rows are never filled with zeros. */
final class PagePerformance
{
    public function __construct(private readonly CurrentProject $current, private readonly ChangeFollowupReport $followups) {}

    /** @return array<string, mixed> */
    public function forProject(Project $project, ?Windows $windows = null): array
    {
        return $this->current->run($project, fn (): array => $this->report($windows ?? new Windows));
    }

    /**
     * A disconnected account can still show its last retained observation
     * window. It is deliberately a separate choice from the normal rolling
     * window, so old measurements are never relabelled as current ones.
     */
    public function latestRecordedWindow(Project $project): ?Windows
    {
        return $this->current->run($project, function (): ?Windows {
            $trackedPages = SitePage::query()->tracked()->select('id');
            $latest = collect([
                PageMetric::query()->whereIn('site_page_id', $trackedPages)->max('measured_on'),
                PageQueryMetric::query()->whereIn('site_page_id', $trackedPages)->max('measured_on'),
            ])->filter()->max();

            return is_string($latest)
                ? new Windows(Carbon::parse($latest, 'America/Los_Angeles'))
                : null;
        });
    }

    /** @return array<string, mixed> */
    private function report(Windows $windows): array
    {
        $sources = [];
        foreach (['gsc_pages', 'gsc_queries', 'ga4_property_landing_paths'] as $source) {
            $latest = MeasurementRead::query()->where('source', $source)->orderByDesc('id')->first();
            $successful = MeasurementRead::query()->where('source', $source)->where('status', ReadStatus::Complete)->orderByDesc('id')->first();
            $covers = $successful !== null && $successful->window_from->toDateString() <= $windows->from->toDateString()
                && $successful->window_to->toDateString() >= $windows->to->toDateString();
            $stalled = $latest?->status === ReadStatus::Reading && $latest->started_at?->lessThan(now()->subMinutes(35));
            $sources[$source] = [
                'status' => $stalled ? ReadStatus::Failed->value : ($latest?->status->value ?? 'not_read'),
                'reason' => $stalled ? 'The report did not finish. Run the measurement again.' : $latest?->reason,
                'finished_at' => $latest?->finished_at?->toIso8601String(),
                'last_successful_at' => $successful?->finished_at?->toIso8601String(),
                'last_successful_read_id' => $successful?->id,
                'stale' => ! $covers || $latest?->status !== ReadStatus::Complete,
                'covers_requested_window' => $covers,
                'metadata' => $latest->metadata ?? [],
            ];
        }
        $dates = [$windows->from->toDateString(), $windows->to->toDateString()];
        $search = PageMetric::query()->whereBetween('measured_on', $dates)->get()->groupBy('site_page_id');
        $queries = PageQueryMetric::query()->whereBetween('measured_on', $dates)->get()->groupBy('site_page_id');
        $analytics = PropertyAnalyticsMetric::query()->whereBetween('measured_on', $dates)->get();
        $pages = SitePage::query()->tracked()->orderBy('url')->get()->map(function (SitePage $page) use ($search, $queries, $windows, $sources): array {
            $searchRows = $search->get($page->id, collect());
            $queryRows = $queries->get($page->id, collect());
            $periods = [];
            foreach (['current' => [$windows->currentFrom->toDateString(), $windows->to->toDateString()], 'previous' => [$windows->from->toDateString(), $windows->previousTo->toDateString()]] as $name => [$from, $to]) {
                $periodSearch = $searchRows->filter(static fn (PageMetric $row): bool => $row->measured_on->toDateString() >= $from && $row->measured_on->toDateString() <= $to);
                $periodQueries = $queryRows->filter(static fn (PageQueryMetric $row): bool => $row->measured_on->toDateString() >= $from && $row->measured_on->toDateString() <= $to);
                $periods[$name] = [
                    'search' => $this->searchTotals($periodSearch),
                    'queries' => $periodQueries->groupBy('query')->map(fn (Collection $rows, string|int $query): array => ['query' => (string) $query, ...$this->searchTotals($rows)])->sortByDesc('impressions')->values()->all(),
                    'analytics' => [...$this->analyticsTotals(collect()), 'status' => 'unavailable', 'reason' => 'Standard GA4 landing paths have no proven origin. Read property-level path evidence separately; use paid orders for page attribution.'],
                ];
            }
            $compares = ! $sources['gsc_pages']['stale']
                && ($periods['current']['search']['impressions'] ?? null) !== null && ($periods['previous']['search']['impressions'] ?? null) !== null;

            return [
                'id' => $page->id, 'url' => $page->canonical_url ?? $page->url, 'title' => $page->title,
                ...$periods,
                'search_comparison_available' => $compares,
                'search_appearance' => $searchRows->sum('impressions') > 0 ? 'observed' : 'unknown',
                'index_status' => 'unknown',
                'daily_search' => $searchRows->sortBy('measured_on')->map(static fn (PageMetric $row): array => [
                    'day' => $row->measured_on->toDateString(), 'impressions' => $row->impressions, 'clicks' => $row->clicks,
                    'position' => $row->position_tenths === null ? null : $row->position_tenths / 10,
                    'measurement_read_id' => $row->measurement_read_id,
                ])->values()->all(),
            ];
        })->all();

        return [
            'windows' => $windows->toArray(), 'sources' => $sources, 'pages' => $pages, 'changes' => $this->followups->get(),
            'analytics' => [
                'scope' => 'selected_landing_paths_in_property', 'landing_origin' => null,
                'reason' => 'These are selected path observations across the connected property, not page-attributed purchases or whole-property totals. The landing origin is unknown.',
                ...$this->analyticsPeriods($analytics, $windows),
                'paths' => $analytics->groupBy('landing_path')->map(fn (Collection $rows, string $path): array => [
                    'path' => $path, ...$this->analyticsPeriods($rows, $windows),
                ])->values()->all(),
            ],
            'notes' => [
                'Search Console returns observed top rows and omits private queries. Missing rows are unknown, not zero.',
                'Search appearance is not a current index inspection or a promise of a ranking.',
                'GA4 purchases are supplementary event reporting with unknown consent coverage. They are never added to paid orders or claimed as new customers.',
                'Search dates use Pacific time; Analytics dates use the property timezone shown in source metadata.',
            ],
        ];
    }

    /** @param Collection<array-key, PageMetric|PageQueryMetric> $rows
     * @return array<string, mixed>
     */
    private function searchTotals(Collection $rows): array
    {
        $knownPositions = $rows->filter(static fn (PageMetric|PageQueryMetric $row): bool => $row->position_tenths !== null);
        $positionImpressions = $knownPositions->sum('impressions');
        $impressions = $rows->isEmpty() ? null : (int) $rows->sum('impressions');
        $clicks = $rows->isEmpty() ? null : (int) $rows->sum('clicks');

        return [
            'impressions' => $impressions, 'clicks' => $clicks,
            'ctr' => $impressions !== null && $impressions > 0 ? $clicks / $impressions : null,
            'position' => $positionImpressions > 0 ? $knownPositions->sum(static fn (PageMetric|PageQueryMetric $row): int => $row->position_tenths * $row->impressions) / $positionImpressions / 10 : null,
            'observed_days' => $rows->map(static fn (PageMetric|PageQueryMetric $row): string => $row->measured_on->toDateString())->unique()->count(),
            'expected_days' => 28,
        ];
    }

    /** @param Collection<array-key, PropertyAnalyticsMetric> $rows
     * @return array<string, mixed>
     */
    private function analyticsPeriods(Collection $rows, Windows $windows): array
    {
        return [
            'current' => $this->analyticsTotals($rows->filter(static fn (PropertyAnalyticsMetric $row): bool => $row->measured_on->toDateString() >= $windows->currentFrom->toDateString())),
            'previous' => $this->analyticsTotals($rows->filter(static fn (PropertyAnalyticsMetric $row): bool => $row->measured_on->toDateString() <= $windows->previousTo->toDateString())),
        ];
    }

    /** @param Collection<array-key, PropertyAnalyticsMetric> $rows
     * @return array<string, mixed>
     */
    private function analyticsTotals(Collection $rows): array
    {
        return [
            'supplementary' => true, 'sessions' => $rows->isEmpty() ? null : (int) $rows->sum('sessions'),
            'reported_purchases' => $rows->isEmpty() ? null : (int) $rows->sum('purchases'),
            'new_customers' => null,
            'observed_days' => $rows->map(static fn (PropertyAnalyticsMetric $row): string => $row->measured_on->toDateString())->unique()->count(),
            'expected_days' => 28,
            'revenue_by_currency' => $rows->groupBy('currency')->map(static fn (Collection $group, string $currency): array => [
                'currency' => $currency,
                'gross_revenue_micros' => (int) $group->sum('gross_revenue_micros'),
                'refund_micros' => (int) $group->sum('refund_micros'),
                'net_revenue_micros' => (int) $group->sum('net_revenue_micros'),
            ])->values()->all(),
            'channels' => $rows->groupBy('channel_group')->map(static fn (Collection $group, string $channel): array => [
                'channel' => $channel, 'sessions' => (int) $group->sum('sessions'), 'reported_purchases' => (int) $group->sum('purchases'),
            ])->values()->all(),
        ];
    }
}
