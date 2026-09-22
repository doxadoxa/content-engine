<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Feedback\Contracts\AnalyticsGateway;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\Followups\CaptureChangeReviews;
use App\Models\MeasurementRead;
use App\Models\PageMetric;
use App\Models\PageQueryMetric;
use App\Models\Project;
use App\Models\PropertyAnalyticsMetric;
use App\Models\SitePage;
use App\Pages\PageUrl;
use App\Pages\TrackedPageIdentity;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class SynchronizePageMeasurements
{
    public function __construct(
        private readonly SearchConsoleGateway $search,
        private readonly AnalyticsGateway $analytics,
        private readonly CurrentProject $current,
        private readonly TrackedPageIdentity $identities,
        private readonly CaptureChangeReviews $followups,
    ) {}

    /**
     * A partial read never replaces a previously successful window with lower
     * numbers. Its outcome remains visible next to the last successful data.
     *
     * @return array<string, MeasurementRead>
     */
    public function sync(Project $project, ?Windows $windows = null, ?string $batchId = null): array
    {
        $windows ??= new Windows;
        $batchId ??= (string) Str::ulid();

        return Cache::lock('page-measurements:'.$project->id, 1900)->get(
            fn (): array => $this->current->run($project, fn (): array => $this->underTenant($project, $windows, $batchId)),
        ) ?: [];
    }

    /** @return array<string, MeasurementRead> */
    private function underTenant(Project $project, Windows $windows, string $batchId): array
    {
        $pages = SitePage::query()->whereNotNull('tracked_at')->get();
        if ($pages->isEmpty()) {
            return [];
        }
        $byUrl = [];
        foreach ($pages as $page) {
            foreach (array_filter([$page->url, $page->canonical_url]) as $url) {
                $byUrl[PageUrl::normalize($url)] = $page;
            }
            $public = $page->snapshots()->where('source_kind', 'public')->first();
            foreach ([$public?->source_url, $public->metadata['requested_url'] ?? null] as $alias) {
                if (is_string($alias) && $this->identities->find($alias)?->id === $page->id) {
                    $byUrl[PageUrl::normalize($alias)] = $page;
                }
            }
        }
        $urls = array_keys($byUrl);
        $readers = [
            'gsc_pages' => fn (): ReadResult => $this->search->pageReport($project, $urls, $windows->from, $windows->to),
            'gsc_queries' => fn (): ReadResult => $this->search->pageReport($project, $urls, $windows->from, $windows->to, true),
            'ga4_property_landing_paths' => fn (): ReadResult => $this->analytics->landingPurchases($project, $urls, $windows->from, $windows->to),
        ];
        $reads = [];
        foreach ($readers as $source => $read) {
            $record = MeasurementRead::query()->create([
                'source' => $source, 'window_from' => $windows->from->toDateString(), 'window_to' => $windows->to->toDateString(),
                'status' => ReadStatus::Reading, 'started_at' => now(),
                'metadata' => ['batch_id' => $batchId, 'page_ids' => $pages->modelKeys(), 'windows' => $windows->toArray()],
            ]);
            try {
                $result = $read();
                DB::transaction(function () use ($record, $result, $source, $byUrl, $windows): void {
                    if ($result->status === ReadStatus::Complete) {
                        $this->replaceWindow($record, $result, $source, $byUrl, $windows);
                    }
                    $record->update([
                        'status' => $result->status, 'reason' => $result->reason, 'row_count' => count($result->rows),
                        'metadata' => [...$record->metadata, ...$result->metadata], 'finished_at' => now(),
                    ]);
                });
            } catch (Throwable $exception) {
                Log::warning('A page measurement read failed', ['project' => $project->id, 'source' => $source, 'exception' => $exception->getMessage()]);
                $record->update(['status' => ReadStatus::Failed, 'reason' => 'The report could not be read. Retry the measurement or check the Google connection.', 'finished_at' => now()]);
            }
            $reads[$source] = $record->refresh();
        }

        $this->followups->refresh($project);

        return $reads;
    }

    /** @param array<string, SitePage> $pages */
    private function replaceWindow(MeasurementRead $read, ReadResult $result, string $source, array $pages, Windows $windows): void
    {
        if ($source === 'ga4_property_landing_paths') {
            $this->replacePropertyWindow($read, $result, array_keys($pages), $windows);

            return;
        }
        $model = match ($source) {
            'gsc_pages' => PageMetric::class,
            'gsc_queries' => PageQueryMetric::class,
            default => throw new \UnexpectedValueException('Unknown measurement source.'),
        };
        $pageIds = array_values(array_unique(array_map(static fn (SitePage $page): string => $page->id, $pages)));
        $model::query()->whereIn('site_page_id', $pageIds)
            ->whereBetween('measured_on', [$windows->from->toDateString(), $windows->to->toDateString()])->delete();

        $aggregated = [];
        foreach ($result->rows as $row) {
            if (! $row instanceof SearchRow) {
                throw new \UnexpectedValueException('A search report row has the wrong type.');
            }
            $page = $pages[PageUrl::normalize($row->url)] ?? null;
            if ($page === null || $row->day < $windows->from->toDateString() || $row->day > $windows->to->toDateString()) {
                continue;
            }
            $key = $page->id.'|'.$row->day;
            $values = ['site_page_id' => $page->id, 'measurement_read_id' => $read->id, 'measured_on' => $row->day];
            if ($source === 'gsc_queries') {
                if ($row->query === null) {
                    throw new \UnexpectedValueException('A search query report row has no query.');
                }
                $key .= '|'.hash('sha256', $row->query);
                $values += ['query' => $row->query, 'query_hash' => hash('sha256', $row->query)];
            }
            $previous = $aggregated[$key] ?? [];
            $values += [
                'impressions' => (int) ($previous['impressions'] ?? 0) + $row->impressions,
                'clicks' => (int) ($previous['clicks'] ?? 0) + $row->clicks,
                'position_weight' => (float) ($previous['position_weight'] ?? 0) + ($row->position ?? 0) * $row->impressions,
                'position_impressions' => (int) ($previous['position_impressions'] ?? 0) + ($row->position === null ? 0 : $row->impressions),
            ];
            $aggregated[$key] = $values;
        }
        foreach ($aggregated as $values) {
            $values['position_tenths'] = $values['position_impressions'] > 0
                ? (int) round($values['position_weight'] / $values['position_impressions'] * 10) : null;
            unset($values['position_weight'], $values['position_impressions']);
            $model::query()->create($values);

        }
    }

    /** @param list<string> $urls */
    private function replacePropertyWindow(MeasurementRead $read, ReadResult $result, array $urls, Windows $windows): void
    {
        $wanted = array_fill_keys(array_map(LandingPath::normalize(...), $urls), true);
        PropertyAnalyticsMetric::query()->whereBetween('measured_on', [$windows->from->toDateString(), $windows->to->toDateString()])->delete();
        $aggregated = [];
        foreach ($result->rows as $row) {
            if (! $row instanceof AnalyticsRow) {
                throw new \UnexpectedValueException('A property Analytics row has the wrong type.');
            }
            $path = LandingPath::normalize($row->path);
            if (! isset($wanted[$path]) || $row->day < $windows->from->toDateString() || $row->day > $windows->to->toDateString()) {
                continue;
            }
            $key = $row->day.'|'.hash('sha256', $path).'|'.$row->channelGroup;
            $previous = $aggregated[$key] ?? [];
            if ($previous !== [] && ($previous['currency'] ?? null) !== $row->currency) {
                throw new \UnexpectedValueException('Mixed report currencies cannot be aggregated.');
            }
            $aggregated[$key] = [
                'measurement_read_id' => $read->id, 'measured_on' => $row->day,
                'landing_path' => $path, 'landing_path_hash' => hash('sha256', $path),
                'channel_group' => $row->channelGroup, 'currency' => $row->currency,
                'sessions' => (int) ($previous['sessions'] ?? 0) + $row->sessions,
                'purchases' => (int) ($previous['purchases'] ?? 0) + $row->purchases,
                'gross_revenue_micros' => (int) ($previous['gross_revenue_micros'] ?? 0) + $row->grossRevenueMicros,
                'refund_micros' => (int) ($previous['refund_micros'] ?? 0) + $row->refundMicros,
                'net_revenue_micros' => (int) ($previous['net_revenue_micros'] ?? 0) + $row->netRevenueMicros,
            ];
        }
        foreach ($aggregated as $values) {
            PropertyAnalyticsMetric::query()->create($values);
        }
    }
}
