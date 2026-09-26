<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\Measurements\ReadResult;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SearchRow;
use App\Feedback\Measurements\SiteSearchRow;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * Search Console for the suite.
 *
 * Scripted per URL and per day, because the thing being tested is a *trend* —
 * a fake that returned one plausible number could not express "this was fine in
 * June and is dying in August", which is the only signal §9.2 acts on.
 */
class FakeSearchConsole implements SearchConsoleGateway
{
    /** @var list<array{dimension: string, from: string, to: string, row_limit: int|null, property: string|null}> */
    public array $siteCalls = [];

    /** @var array<int, ReadResult> */
    private array $pageReports = [];

    /** @var list<UnitMetrics> */
    private array $rows = [];

    /** @var array<string, ReadResult> keyed by dimension, or `dimension@from` for one period */
    private array $siteReports = [];

    private bool $configured = true;

    public function willReadPages(ReadResult $result, bool $queries = false): self
    {
        $this->pageReports[(int) $queries] = $result;

        return $this;
    }

    /** @param list<string> $urls */
    public function pageReport(Project $project, array $urls, Carbon $from, Carbon $to, bool $queries = false): ReadResult
    {
        if (! $this->configured) {
            return new ReadResult(ReadStatus::Unavailable, reason: 'Search Console is not connected.');
        }

        return $this->pageReports[(int) $queries] ?? new ReadResult(
            ReadStatus::Complete,
            $queries ? [] : array_map(static fn (UnitMetrics $row) => new SearchRow(
                $row->url, $row->measuredOn->toDateString(), $row->impressions, $row->clicks, $row->position,
            ), $this->performance($project, $urls, $from, $to)),
        );
    }

    /**
     * Script a whole-property report. `$from` narrows it to the call whose
     * window starts that day — the query and page lists are read twice, once
     * per 28-day period, and a test comparing them needs two answers.
     */
    public function willReadSite(string $dimension, ReadResult $result, ?string $from = null): self
    {
        $this->siteReports[$from === null ? $dimension : $dimension.'@'.$from] = $result;

        return $this;
    }

    /**
     * Unscripted, the site report is derived from the per-URL rows given to
     * {@see willReport()}: summed per day for `date`, per URL for `page`, and
     * empty for `query`, which those rows cannot express.
     */
    public function siteReport(Project $project, Carbon $from, Carbon $to, string $dimension, ?int $rowLimit = null, ?string $property = null): ReadResult
    {
        $this->siteCalls[] = ['dimension' => $dimension, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'row_limit' => $rowLimit, 'property' => $property];
        if (! $this->configured) {
            return new ReadResult(ReadStatus::Unavailable, reason: 'Search Console is not connected.');
        }
        $scripted = $this->siteReports[$dimension.'@'.$from->toDateString()] ?? $this->siteReports[$dimension] ?? null;
        if ($scripted !== null) {
            return $scripted;
        }
        $grouped = [];
        foreach ($this->rows as $row) {
            if (! $row->measuredOn->betweenIncluded($from, $to) || $dimension === 'query') {
                continue;
            }
            $key = $dimension === 'date' ? $row->measuredOn->toDateString() : $row->url;
            $grouped[$key] = [
                'impressions' => ($grouped[$key]['impressions'] ?? 0) + $row->impressions,
                'clicks' => ($grouped[$key]['clicks'] ?? 0) + $row->clicks,
            ];
        }
        $rows = [];
        foreach ($grouped as $key => $totals) {
            $rows[] = new SiteSearchRow(
                $dimension === 'date' ? (string) $key : null, $dimension === 'date' ? null : (string) $key,
                $totals['impressions'], $totals['clicks'], null,
            );
        }
        if ($dimension !== 'date') {
            usort($rows, static fn (SiteSearchRow $a, SiteSearchRow $b): int => $b->clicks <=> $a->clicks);
            $rows = array_slice($rows, 0, $rowLimit ?? 250);
        }

        return new ReadResult(ReadStatus::Complete, $rows);
    }

    public function name(): string
    {
        return 'fake';
    }

    /** Configured unless a test says otherwise — the common case needs no setup. */
    public function isConfiguredFor(Project $project): bool
    {
        return $this->configured;
    }

    public function willBeUnconfigured(): self
    {
        $this->configured = false;

        return $this;
    }

    public function willReport(string $url, string $day, int $impressions, int $clicks, ?float $position = 8.0): self
    {
        $this->rows[] = new UnitMetrics($url, Carbon::parse($day), $impressions, $clicks, $position);

        return $this;
    }

    /**
     * A run of days that decays linearly — the shape §9.2 has to notice.
     */
    public function willDecay(string $url, string $from, int $days, int $startImpressions, int $endImpressions): self
    {
        $start = Carbon::parse($from);
        $step = ($startImpressions - $endImpressions) / max(1, $days - 1);

        for ($i = 0; $i < $days; $i++) {
            $impressions = (int) round($startImpressions - ($step * $i));

            $this->willReport(
                $url,
                $start->copy()->addDays($i)->toDateString(),
                $impressions,
                intdiv($impressions, 20),
            );
        }

        return $this;
    }

    /**
     * @param  list<string>  $urls
     * @return list<UnitMetrics>
     */
    public function performance(Project $project, array $urls, Carbon $from, Carbon $to): array
    {
        $wanted = array_flip($urls);

        return array_values(array_filter(
            $this->rows,
            static fn (UnitMetrics $row): bool => isset($wanted[$row->url])
                && $row->measuredOn->betweenIncluded($from, $to),
        ));
    }
}
