<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Contracts\SearchConsoleGateway;
use App\Models\Project;
use App\Visibility\BrandPresence;
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
    /** @var list<UnitMetrics> */
    private array $rows = [];

    /**
     * Brand queries by day: `Y-m-d` => query => [impressions, clicks].
     *
     * Scripted per day like everything else here, because §6 stores brand
     * demand as a daily row and the thing worth asserting is the shape over
     * days rather than one reading.
     *
     * @var array<string, array<string, array{int, int}>>
     */
    private array $brand = [];

    private bool $configured = true;

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
     * A query somebody typed on a day, exactly as Search Console would say it.
     *
     * Raw and unmatched on purpose: {@see brandDemand()} below runs the same
     * {@see BrandPresence::namesBrand()} the real adapter runs, so a test can
     * script "cleaningpoint lisbon" and "how to clean windows" and assert that
     * one of them counted. A fake that took a pre-matched total would let the
     * matching rot untested everywhere except the adapter's own suite.
     */
    public function willBeSearchedFor(string $day, string $query, int $impressions, int $clicks = 0): self
    {
        $date = Carbon::parse($day)->toDateString();

        $this->brand[$date][$query] = [$impressions, $clicks];

        return $this;
    }

    /**
     * Brand demand over the window, matched on the project's name.
     *
     * Null when unconfigured, which is the contract's whole point: the capture
     * has to be able to write "not measured" rather than a zero that looks like
     * a brand nobody searched for.
     */
    public function brandDemand(Project $project, Carbon $from, Carbon $to): ?BrandDemand
    {
        if (! $this->configured) {
            return null;
        }

        $impressions = 0;
        $clicks = 0;
        $queries = [];

        foreach ($this->brand as $day => $rows) {
            if (! Carbon::parse($day)->betweenIncluded($from, $to)) {
                continue;
            }

            foreach ($rows as $query => [$seen, $clicked]) {
                if (! BrandPresence::namesBrand($query, $project->name)) {
                    continue;
                }

                $impressions += $seen;
                $clicks += $clicked;
                $queries[$query] = ($queries[$query] ?? 0) + $seen;
            }
        }

        arsort($queries);

        return new BrandDemand($impressions, $clicks, $queries);
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
