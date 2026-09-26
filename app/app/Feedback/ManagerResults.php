<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Measurements\PagePerformance;
use App\Feedback\Measurements\SiteSearchReport;
use App\Integrations\Google\GooglePanel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\PurchaseSource;
use App\Purchases\PurchaseReport;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\DashboardVisibility;
use Carbon\CarbonImmutable;

/** A compact reading of the same observations used by the detailed reports. */
final class ManagerResults
{
    public function __construct(private readonly PagePerformance $performance, private readonly DashboardVisibility $visibility, private readonly CurrentProject $current, private readonly GooglePanel $google, private readonly PurchaseReport $purchases, private readonly SiteSearchReport $siteSearch) {}

    /**
     * @param  array<string, mixed>|null  $report
     * @return array<string, mixed>
     */
    public function for(Project $project, ?array $report = null): array
    {
        return $this->current->run($project, fn (): array => $this->build($project, $report));
    }

    /**
     * @param  array<string, mixed>|null  $report
     * @return array<string, mixed>
     */
    private function build(Project $project, ?array $report): array
    {
        $report ??= $this->performance->forProject($project);
        /** @var list<array<string, mixed>> $pageRows */
        $pageRows = $report['pages'];
        $pages = collect($pageRows);
        $current = $pages->pluck('current.search');
        $previous = $pages->pluck('previous.search');
        $observed = $current->filter(fn (array $row): bool => $row['clicks'] !== null);
        $comparison = $pages->isNotEmpty() && $pages->every(fn (array $page): bool => $page['search_comparison_available']);
        $daily = $pages->flatMap(fn (array $page): array => $page['daily_search'])->groupBy('day');
        $series = [];
        $from = CarbonImmutable::parse($report['windows']['current']['from']);
        $to = CarbonImmutable::parse($report['windows']['current']['to']);
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $rows = $daily->get($day->toDateString());
            $series[] = ['day' => $day->toDateString(), 'clicks' => $rows?->sum('clicks'),
                'impressions' => $rows?->sum('impressions'), 'observed_pages' => $rows?->count() ?? 0];
        }
        $search = [
            'clicks' => $observed->isEmpty() ? null : (int) $observed->sum('clicks'),
            'impressions' => $observed->isEmpty() ? null : (int) $observed->sum('impressions'),
            'previous_clicks' => $comparison ? (int) $previous->sum('clicks') : null,
            'previous_impressions' => $comparison ? (int) $previous->sum('impressions') : null,
            'observed_pages' => $observed->count(), 'tracked_pages' => $pages->count(),
            'from' => $report['windows']['current']['from'], 'to' => $report['windows']['current']['to'],
            'stale' => $report['sources']['gsc_pages']['stale'],
            'connected' => $this->google->connectionState($project)['search_console'],
            'status' => $report['sources']['gsc_pages']['status'],
            'updated_at' => $report['sources']['gsc_pages']['last_successful_at'],
            'daily' => $series,
            'scope' => 'tracked_pages',
            'reason' => null,
        ];
        $site = $this->siteSearch->forProject($project);
        $search['state'] = $site['state'];
        // Once Search Console is connected the card is about the site — what
        // Google sends to all of it — in every state, including the ones with
        // no numbers yet, so it can say why. Tracked pages remain only for a
        // project with no usable Search Console connection.
        if ($site['state'] !== 'not_connected') {
            $search = $this->siteCard($project, $site, $search);
        }
        $source = PurchaseSource::query()->where('is_primary', true)->first();
        $purchaseEnd = CarbonImmutable::now('UTC')->addDay()->startOfDay();
        $purchases = $this->purchases->summarize($source, $purchaseEnd->subDays(28), $purchaseEnd);

        return [
            'published_articles' => ContentItem::query()->whereIn('state', ['published', 'refreshing'])->count(),
            'search' => $search,
            ...$this->visibility->get(),
            'purchases' => [
                'count' => $purchases['status'] === 'unavailable' ? null : array_sum(array_column($purchases['currencies'], 'completed_sales')),
                'status' => $purchases['status'], 'connected' => $source !== null, 'paused' => $source !== null && ! $source->is_enabled,
                'from' => $purchaseEnd->subDays(28)->toDateString(), 'to' => $purchaseEnd->subDay()->toDateString(),
                'updated_at' => $purchases['last_received_at'],
            ],
        ];
    }

    /**
     * The same card shape, filled from the whole property.
     *
     * `observed_pages` on a day is 1 when the property has a stored row for it
     * and 0 when it does not, so a missing day stays a gap in the chart rather
     * than a zero. On the card itself it counts the pages Search Console listed
     * in the current window's top pages.
     *
     * @param  array<string, mixed>  $site
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function siteCard(Project $project, array $site, array $fallback): array
    {
        /** @var list<array{day: string, clicks: int, impressions: int}> $days */
        $days = $site['daily'];
        $byDay = collect($days)->keyBy('day');
        $series = [];
        $from = CarbonImmutable::parse($site['windows']['current']['from']);
        $to = CarbonImmutable::parse($site['windows']['current']['to']);
        // Numbers only for a ready report. Stored rows under any other state —
        // a paused project, say — are not what the card claims to show.
        $ready = $site['state'] === 'ready';
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $row = $ready ? $byDay->get($day->toDateString()) : null;
            $series[] = ['day' => $day->toDateString(), 'clicks' => $row['clicks'] ?? null,
                'impressions' => $row['impressions'] ?? null, 'observed_pages' => $row === null ? 0 : 1];
        }
        $status = $this->siteSearch->dailyStatus($project);

        return [
            ...$fallback,
            'clicks' => $ready ? ($site['current']['clicks'] ?? null) : null,
            'impressions' => $ready ? ($site['current']['impressions'] ?? null) : null,
            'previous_clicks' => $ready ? ($site['previous']['clicks'] ?? null) : null,
            'previous_impressions' => $ready ? ($site['previous']['impressions'] ?? null) : null,
            'observed_pages' => $ready ? count($site['top_pages']) : 0,
            'from' => $site['windows']['current']['from'], 'to' => $site['windows']['current']['to'],
            'stale' => $status['stale'],
            'status' => $status['status'],
            'updated_at' => $site['updated_at'],
            'daily' => $series,
            'scope' => 'site',
            'reason' => $site['reason'],
        ];
    }
}
