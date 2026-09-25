<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\Measurements\ReadResult;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SearchRow;
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
    /** @var array<int, ReadResult> */
    private array $pageReports = [];

    /** @var list<UnitMetrics> */
    private array $rows = [];

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
