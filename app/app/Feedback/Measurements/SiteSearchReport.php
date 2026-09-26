<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Integrations\Google\GoogleConnection;
use App\Models\MeasurementRead;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\SitePage;
use App\Models\SiteSearchDay;
use App\Models\SiteSearchTopRow;
use App\Pages\PageUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Read model for the whole Search Console property.
 *
 * `state` answers the one question the screen has to get right first: is there
 * nothing because nothing is connected, because it is still being read, because
 * the read failed, or because Google genuinely has nothing for this site? Those
 * look identical as an empty chart and each asks the owner for something else.
 */
final class SiteSearchReport
{
    /** A read, or a request for one, still unanswered after this long is treated as failed, like {@see PagePerformance}. */
    public const int STALLED_AFTER_MINUTES = 35;

    private const array SOURCES = [SynchronizeSiteSearch::DAILY, SynchronizeSiteSearch::QUERIES, SynchronizeSiteSearch::PAGES];

    public function __construct(private readonly CurrentProject $current, private readonly GoogleConnection $connection) {}

    /** @return array<string, mixed> */
    public function forProject(Project $project): array
    {
        $integration = $this->connection->for($project);

        return $this->current->run($project, fn (): array => $this->report($project, $integration));
    }

    /**
     * The daily read's status for a compact card: `not_read`, a ReadStatus
     * value (stalled reads reported as `failed`), and whether the stored days
     * are behind today's window.
     *
     * @return array{status: string, stale: bool}
     */
    public function dailyStatus(Project $project): array
    {
        $property = $this->property($this->connection->for($project));

        return $this->current->run($project, function () use ($property): array {
            $latest = $this->latest(SynchronizeSiteSearch::DAILY, $property);
            $complete = $this->latestComplete(SynchronizeSiteSearch::DAILY, $property);

            return [
                'status' => $latest === null ? 'not_read' : ($this->stalled($latest) ? ReadStatus::Failed->value : $latest->status->value),
                'stale' => $complete === null || $latest?->status !== ReadStatus::Complete
                    || $complete->window_to->toDateString() < (new Windows)->to->toDateString(),
            ];
        });
    }

    /**
     * Whether the performance page should queue a read on its own: a selected
     * property that has never been read and that nobody has asked about yet.
     *
     * Covers projects that connected before site-wide reads existed, and a
     * switch whose read was blocked by the previous one still running. Only
     * when no request is remembered, so a page polling every few seconds does
     * not queue a read per poll, and a request that went unanswered is shown
     * as failed instead of quietly re-queued forever.
     */
    public function needsFirstRead(Project $project): bool
    {
        $property = $this->property($this->connection->for($project));

        return $property !== null
            && SyncSiteSearchJob::eligible($project)
            && SyncSiteSearchJob::requestedAt($project->id) === null
            && $this->current->run($project, fn (): bool => $this->latest(SynchronizeSiteSearch::DAILY, $property) === null);
    }

    /** @return array<string, mixed> */
    private function report(Project $project, ?ProjectIntegration $integration): array
    {
        $property = $this->property($integration);
        $latest = $this->latest(SynchronizeSiteSearch::DAILY, $property);
        $complete = $this->latestComplete(SynchronizeSiteSearch::DAILY, $property);
        $reading = $this->reads($property)->whereIn('source', self::SOURCES)
            ->where('status', ReadStatus::Reading)
            ->where('started_at', '>=', now()->subMinutes(self::STALLED_AFTER_MINUTES))
            ->exists();
        // The window the stored numbers were read for, not today's: a read
        // that ended on the 12th stays a window ending on the 12th until the
        // next complete read, rather than sliding and losing its newest days.
        $windows = $complete === null
            ? new Windows
            : new Windows(Carbon::parse($complete->window_to->toDateString(), 'America/Los_Angeles'));

        $days = SiteSearchDay::query()->whereIn('measurement_read_id', $this->reads($property)->select('id'))->orderBy('measured_on')->get();
        [$state, $reason] = $this->state($project, $integration, $latest, $complete, $days->isNotEmpty(), $reading);

        $current = [$windows->currentFrom->toDateString(), $windows->to->toDateString()];
        $previous = [$windows->from->toDateString(), $windows->previousTo->toDateString()];
        $within = static fn (array $range): Collection => $days->filter(static fn (SiteSearchDay $day): bool => $day->measured_on->toDateString() >= $range[0] && $day->measured_on->toDateString() <= $range[1]);

        $allWindows = $windows->toArray();

        return [
            'state' => $state,
            'property' => $integration?->searchConsoleSite(),
            'reason' => $reason,
            'reading' => $reading,
            'updated_at' => $complete?->finished_at?->toIso8601String(),
            'windows' => ['current' => $allWindows['current'], 'previous' => $allWindows['previous']],
            'current' => $this->totals($within($current)),
            'previous' => $this->totals($within($previous)),
            'history_from' => $days->first()?->measured_on->toDateString(),
            'daily' => $days->map(static fn (SiteSearchDay $day): array => [
                'day' => $day->measured_on->toDateString(), 'clicks' => $day->clicks, 'impressions' => $day->impressions,
                'position' => $day->position_tenths === null ? null : $day->position_tenths / 10,
            ])->values()->all(),
            'top_queries' => array_map(static fn (array $row): array => ['query' => $row['value'], ...$row['metrics']], $this->top('query', $property)),
            'top_pages' => $this->topPages($property),
        ];
    }

    /** @return array{string, string|null} */
    private function state(Project $project, ?ProjectIntegration $integration, ?MeasurementRead $latest, ?MeasurementRead $complete, bool $hasDays, bool $reading): array
    {
        if ($integration === null || ! $integration->isUsable() || ! $integration->grants(ProjectIntegration::SCOPE_SEARCH_CONSOLE)) {
            return ['not_connected', $integration !== null && ! $integration->isUsable()
                ? ($integration->failure_reason ?? 'Reconnect Google to keep reading Search Console.')
                : 'Connect Google Search Console to see how your site performs in search.'];
        }
        if ($integration->searchConsoleSite() === null) {
            return ['no_property', 'Choose which Search Console property belongs to this site.'];
        }
        if (! SyncSiteSearchJob::eligible($project)) {
            return ['paused', 'This project is paused, so search data is not being read.'];
        }
        if ($complete === null) {
            if ($reading || ($latest?->status === ReadStatus::Reading && ! $this->stalled($latest))) {
                return ['reading', null];
            }
            if ($latest !== null) {
                return ['failed', $this->stalled($latest)
                    ? 'The report did not finish. Run the measurement again.'
                    : ($latest->reason ?? 'Search Console could not be read.')];
            }
            // Nothing recorded yet. Reading only while the request is recent:
            // a queued job that never ran leaves no record behind, and
            // "reading" forever is a screen that polls forever.
            $requested = SyncSiteSearchJob::requestedAt($project->id);
            if ($requested !== null && $requested->greaterThanOrEqualTo(now()->subMinutes(self::STALLED_AFTER_MINUTES))) {
                return ['reading', null];
            }

            return ['failed', 'Google has not answered yet. Try refreshing search data.'];
        }

        return [$hasDays ? 'ready' : 'no_data', null];
    }

    /**
     * @param  Collection<array-key, SiteSearchDay>  $days
     * @return array{clicks: int, impressions: int, ctr: float|int|null, position: float|int|null}|null
     */
    private function totals(Collection $days): ?array
    {
        if ($days->isEmpty()) {
            return null;
        }
        $clicks = (int) $days->sum('clicks');
        $impressions = (int) $days->sum('impressions');
        $positioned = $days->filter(static fn (SiteSearchDay $day): bool => $day->position_tenths !== null);
        $positionImpressions = (int) $positioned->sum('impressions');

        return [
            'clicks' => $clicks, 'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : null,
            'position' => $positionImpressions > 0
                ? $positioned->sum(static fn (SiteSearchDay $day): int => (int) $day->position_tenths * $day->impressions) / $positionImpressions / 10
                : null,
        ];
    }

    /** @return list<array{value: string, metrics: array<string, mixed>}> */
    private function top(string $kind, ?string $property): array
    {
        $rows = SiteSearchTopRow::query()->where('kind', $kind)
            ->whereIn('measurement_read_id', $this->reads($property)->select('id'))
            ->orderBy('rank')->get()->groupBy('period');
        $previous = $rows->get('previous', collect())->keyBy('value_hash');

        // Ranked by clicks when stored, so rank order is clicks-descending order.
        return array_values($rows->get('current', collect())->map(function (SiteSearchTopRow $row) use ($previous): array {
            $match = $previous->get($row->value_hash);

            /** @var array<string, mixed> $metrics */
            $metrics = [...$this->metrics($row), 'previous' => $match instanceof SiteSearchTopRow ? $this->metrics($match) : null];

            return ['value' => $row->value, 'metrics' => $metrics];
        })->all());
    }

    /** @return list<array<string, mixed>> */
    private function topPages(?string $property): array
    {
        $tracked = [];
        foreach (SitePage::query()->tracked()->get(['id', 'url', 'canonical_url']) as $page) {
            foreach (array_filter([$page->url, $page->canonical_url]) as $url) {
                $key = $this->normalized($url);
                if ($key !== null) {
                    $tracked[$key] ??= $page->id;
                }
            }
        }

        return array_map(function (array $row) use ($tracked): array {
            $key = $this->normalized($row['value']);
            $pageId = $key === null ? null : ($tracked[$key] ?? null);

            return ['url' => $row['value'], ...$row['metrics'], 'tracked' => $pageId !== null, 'page_id' => $pageId];
        }, $this->top('page', $property));
    }

    /** @return array{clicks: int, impressions: int, ctr: float|int|null, position: float|int|null} */
    private function metrics(SiteSearchTopRow $row): array
    {
        return [
            'clicks' => $row->clicks, 'impressions' => $row->impressions,
            'ctr' => $row->impressions > 0 ? $row->clicks / $row->impressions : null,
            'position' => $row->position_tenths === null ? null : $row->position_tenths / 10,
        ];
    }

    private function normalized(string $url): ?string
    {
        try {
            return PageUrl::normalize($url);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Reads of one property only. Tagged at the start of each sync, so a read
     * of the property the owner switched away from — or one still finishing
     * when they switched — is never shown under the new property's name.
     *
     * @return Builder<MeasurementRead>
     */
    private function reads(?string $property): Builder
    {
        $query = MeasurementRead::query();

        return $property === null ? $query->whereRaw('1 = 0') : $query->where('metadata->property', $property);
    }

    private function property(?ProjectIntegration $integration): ?string
    {
        return $integration !== null && $integration->isUsable() ? $integration->searchConsoleSite() : null;
    }

    private function latest(string $source, ?string $property): ?MeasurementRead
    {
        return $this->reads($property)->where('source', $source)->orderByDesc('id')->first();
    }

    private function latestComplete(string $source, ?string $property): ?MeasurementRead
    {
        return $this->reads($property)->where('source', $source)->where('status', ReadStatus::Complete)->orderByDesc('id')->first();
    }

    private function stalled(MeasurementRead $read): bool
    {
        return $read->status === ReadStatus::Reading && $read->started_at?->lessThan(now()->subMinutes(self::STALLED_AFTER_MINUTES)) === true;
    }
}
