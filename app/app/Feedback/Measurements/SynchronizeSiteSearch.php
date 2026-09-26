<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Feedback\Contracts\SearchConsoleGateway;
use App\Integrations\Google\GoogleConnection;
use App\Models\MeasurementRead;
use App\Models\Project;
use App\Models\SiteSearchDay;
use App\Models\SiteSearchTopRow;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Reads the whole Search Console property: sixteen months of daily totals, and
 * the top queries and pages for the current and previous 28 days.
 *
 * Needs no tracked pages. That is the reason it exists — connecting Search
 * Console used to show nothing until somebody chose pages to track, which
 * nobody does before they have seen a reason to.
 *
 * Its own lock, separate from {@see SynchronizePageMeasurements}: the two read
 * different things and neither should wait half an hour behind the other.
 */
final class SynchronizeSiteSearch
{
    public const string DAILY = 'gsc_site_daily';

    public const string QUERIES = 'gsc_site_queries';

    public const string PAGES = 'gsc_site_pages';

    /** Google keeps sixteen months of Search Console history. */
    public const int HISTORY_MONTHS = 16;

    public const int TOP_ROWS = 250;

    public function __construct(
        private readonly SearchConsoleGateway $search,
        private readonly CurrentProject $current,
        private readonly GoogleConnection $connection,
    ) {}

    /**
     * Only a Complete result replaces stored rows. A Partial or failed read
     * is recorded next to the last complete data and never over it: a
     * truncated report looks exactly like a site that lost traffic.
     *
     * @return array<string, MeasurementRead> empty when a sync is already running
     */
    public function sync(Project $project, ?Windows $windows = null, ?string $batchId = null): array
    {
        $windows ??= new Windows;
        $batchId ??= (string) Str::ulid();

        return Cache::lock('site-search:'.$project->id, 1900)->get(
            fn (): array => $this->current->run($project, fn (): array => $this->underTenant($project, $windows, $batchId)),
        ) ?: [];
    }

    public static function historyFrom(Windows $windows): Carbon
    {
        return $windows->to->copy()->subMonthsNoOverflow(self::HISTORY_MONTHS)->addDay();
    }

    /** @return array<string, MeasurementRead> */
    private function underTenant(Project $project, Windows $windows, string $batchId): array
    {
        // Captured once. Every read of this sync is about this property and is
        // tagged with it, so the report can ignore reads of a property the
        // owner has since switched away from.
        $site = $this->currentSite($project);
        $historyFrom = self::historyFrom($windows);
        $periods = [
            'current' => [$windows->currentFrom, $windows->to],
            'previous' => [$windows->from, $windows->previousTo],
        ];
        $readers = [
            self::DAILY => [$historyFrom, fn (): array => ['all' => $this->search->siteReport($project, $historyFrom, $windows->to, 'date', null, $site)]],
            self::QUERIES => [$windows->from, fn (): array => $this->ranked($project, $periods, 'query', $site)],
            self::PAGES => [$windows->from, fn (): array => $this->ranked($project, $periods, 'page', $site)],
        ];
        $reads = [];
        $switched = false;
        foreach ($readers as $source => [$from, $read]) {
            $record = MeasurementRead::query()->create([
                'source' => $source, 'window_from' => $from->toDateString(), 'window_to' => $windows->to->toDateString(),
                'status' => ReadStatus::Reading, 'started_at' => now(),
                'metadata' => ['batch_id' => $batchId, 'scope' => 'property', 'property' => $site, 'windows' => $windows->toArray()],
            ]);
            try {
                /** @var array<string, ReadResult> $results */
                $results = $site === null
                    ? ['all' => new ReadResult(ReadStatus::Unavailable, reason: 'No Search Console property is selected.')]
                    : $read();
                $status = $this->worst($results);
                DB::transaction(function () use ($project, $site, $record, $results, $status, $source, $windows, $historyFrom, $periods, &$switched): void {
                    // Locked, so a switch or a disconnect deleting this read
                    // waits for the store to finish or has already happened.
                    // Gone means exactly that: the owner moved on.
                    $locked = MeasurementRead::query()->whereKey($record->id)->lockForUpdate()->first();
                    if ($locked === null) {
                        $switched = true;

                        return;
                    }
                    // The owner may have chosen another property while Google
                    // was answering. Storing these rows would put one site's
                    // numbers under the other's name.
                    if ($site !== null && $this->currentSite($project) !== $site) {
                        $switched = true;
                        $locked->update(['status' => ReadStatus::Failed, 'reason' => 'The property changed during the read.', 'finished_at' => now()]);

                        return;
                    }
                    if ($status === ReadStatus::Complete) {
                        $source === self::DAILY
                            ? $this->replaceDays($record, $results['all'], $historyFrom, $windows)
                            : $this->replaceTopRows($record, $results, $source === self::QUERIES ? 'query' : 'page', $periods);
                    }
                    $record->update([
                        'status' => $status,
                        'reason' => $this->reason($results, $status),
                        'row_count' => array_sum(array_map(static fn (ReadResult $result): int => count($result->rows), $results)),
                        'metadata' => [...$record->metadata, ...$this->metadata($results), 'property' => $site],
                        'finished_at' => now(),
                    ]);
                });
            } catch (Throwable $exception) {
                Log::warning('A site search read failed', ['project' => $project->id, 'source' => $source, 'exception' => $exception->getMessage()]);
                $record->update(['status' => ReadStatus::Failed, 'reason' => 'The report could not be read. Retry the measurement or check the Google connection.', 'finished_at' => now()]);
            }
            // fresh(), not refresh(): switching property deletes this
            // property's reads, possibly this very one.
            $fresh = $record->fresh();
            if ($fresh !== null) {
                $reads[$source] = $fresh;
            }
            if ($switched) {
                // Nothing further is about the property the owner chose: no
                // more requests to Google, no more reads left behind for it.
                break;
            }
        }
        if ($switched) {
            // This job holds the unique lock, so the switch could not queue a
            // read of the new property. Forgetting the request lets the
            // performance page's self-heal queue it once this job ends.
            SyncSiteSearchJob::forgetRequest($project->id);
        }

        return $reads;
    }

    private function currentSite(Project $project): ?string
    {
        $integration = $this->connection->for($project);

        return $integration !== null && $integration->isUsable() ? $integration->searchConsoleSite() : null;
    }

    /**
     * @param  array<string, array{Carbon, Carbon}>  $periods
     * @return array<string, ReadResult>
     */
    private function ranked(Project $project, array $periods, string $dimension, ?string $site): array
    {
        $results = [];
        foreach ($periods as $period => [$from, $to]) {
            $results[$period] = $this->search->siteReport($project, $from, $to, $dimension, self::TOP_ROWS, $site);
        }

        return $results;
    }

    /** @param array<string, ReadResult> $results */
    private function worst(array $results): ReadStatus
    {
        $rank = static fn (ReadStatus $status): int => match ($status) {
            ReadStatus::Complete => 0, ReadStatus::Partial => 1, ReadStatus::Unavailable => 2,
            ReadStatus::Incompatible => 3, ReadStatus::Failed, ReadStatus::Reading => 4,
        };
        $worst = ReadStatus::Complete;
        foreach ($results as $result) {
            if ($rank($result->status) > $rank($worst)) {
                $worst = $result->status;
            }
        }

        return $worst;
    }

    /** @param array<string, ReadResult> $results */
    private function reason(array $results, ReadStatus $status): ?string
    {
        foreach ($results as $result) {
            if ($result->status === $status && $result->reason !== null) {
                return $result->reason;
            }
        }

        return null;
    }

    /**
     * @param  array<string, ReadResult>  $results
     * @return array<string, mixed>
     */
    private function metadata(array $results): array
    {
        if (array_keys($results) === ['all']) {
            return $results['all']->metadata;
        }
        $periods = [];
        foreach ($results as $period => $result) {
            $periods[$period] = [...$result->metadata, 'status' => $result->status->value, 'reason' => $result->reason, 'row_count' => count($result->rows)];
        }

        return ['periods' => $periods];
    }

    /**
     * Everything up to the end of the window is restated, including days older
     * than Google still keeps: a day Google has forgotten cannot be corrected,
     * and the history shown should be the history Search Console would show.
     */
    private function replaceDays(MeasurementRead $read, ReadResult $result, Carbon $historyFrom, Windows $windows): void
    {
        $from = $historyFrom->toDateString();
        $to = $windows->to->toDateString();
        SiteSearchDay::query()->where('measured_on', '<=', $to)->delete();
        $aggregated = [];
        foreach ($result->rows as $row) {
            if (! $row instanceof SiteSearchRow || $row->day === null) {
                throw new UnexpectedValueException('A site search day row has the wrong type.');
            }
            if ($row->day < $from || $row->day > $to) {
                continue;
            }
            $aggregated[$row->day] = $this->add($aggregated[$row->day] ?? null, $row);
        }
        foreach ($aggregated as $day => $values) {
            SiteSearchDay::query()->create([
                'measurement_read_id' => $read->id, 'measured_on' => $day,
                ...$this->finish($values),
            ]);
        }
    }

    /**
     * @param  array<string, ReadResult>  $results
     * @param  array<string, array{Carbon, Carbon}>  $periods
     */
    private function replaceTopRows(MeasurementRead $read, array $results, string $kind, array $periods): void
    {
        SiteSearchTopRow::query()->where('kind', $kind)->delete();
        foreach ($results as $period => $result) {
            [$from, $to] = $periods[$period];
            $aggregated = [];
            foreach ($result->rows as $row) {
                if (! $row instanceof SiteSearchRow || $row->key === null) {
                    throw new UnexpectedValueException('A site search ranking row has the wrong type.');
                }
                $aggregated[$row->key] = $this->add($aggregated[$row->key] ?? null, $row);
            }
            // Google already sorts by clicks. Sorted again, stably, so a rank
            // never depends on an ordering nobody promised to keep.
            uasort($aggregated, static fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks']);
            $rank = 0;
            foreach ($aggregated as $value => $values) {
                SiteSearchTopRow::query()->create([
                    'measurement_read_id' => $read->id, 'kind' => $kind, 'period' => $period,
                    'window_from' => $from->toDateString(), 'window_to' => $to->toDateString(),
                    'value' => (string) $value, 'value_hash' => hash('sha256', (string) $value), 'rank' => ++$rank,
                    ...$this->finish($values),
                ]);
            }
        }
    }

    /**
     * @param  array{clicks: int, impressions: int, weight: float, positioned: int}|null  $previous
     * @return array{clicks: int, impressions: int, weight: float, positioned: int}
     */
    private function add(?array $previous, SiteSearchRow $row): array
    {
        return [
            'clicks' => ($previous['clicks'] ?? 0) + $row->clicks,
            'impressions' => ($previous['impressions'] ?? 0) + $row->impressions,
            'weight' => ($previous['weight'] ?? 0.0) + ($row->position ?? 0) * $row->impressions,
            'positioned' => ($previous['positioned'] ?? 0) + ($row->position === null ? 0 : $row->impressions),
        ];
    }

    /**
     * @param  array{clicks: int, impressions: int, weight: float, positioned: int}  $values
     * @return array{clicks: int, impressions: int, position_tenths: int|null}
     */
    private function finish(array $values): array
    {
        return [
            'clicks' => $values['clicks'], 'impressions' => $values['impressions'],
            'position_tenths' => $values['positioned'] > 0 ? (int) round($values['weight'] / $values['positioned'] * 10) : null,
        ];
    }
}
