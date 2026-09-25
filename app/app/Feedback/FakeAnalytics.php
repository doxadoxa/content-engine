<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Contracts\AnalyticsGateway;
use App\Feedback\Measurements\ReadResult;
use App\Feedback\Measurements\ReadStatus;
use App\Models\Project;
use Illuminate\Support\Carbon;

/** Analytics for the suite. */
class FakeAnalytics implements AnalyticsGateway
{
    private ?ReadResult $purchaseReport = null;

    /** @var list<UnitEngagement> */
    private array $rows = [];

    private bool $configured = true;

    public function willReadPurchases(ReadResult $result): self
    {
        $this->purchaseReport = $result;

        return $this;
    }

    /** @param list<string> $urls */
    public function landingPurchases(Project $project, array $urls, Carbon $from, Carbon $to): ReadResult
    {
        return ! $this->configured
            ? new ReadResult(ReadStatus::Unavailable, reason: 'Analytics is not connected.')
            : ($this->purchaseReport ?? new ReadResult(ReadStatus::Complete));
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfiguredFor(Project $project): bool
    {
        return $this->configured;
    }

    public function willBeUnconfigured(): self
    {
        $this->configured = false;

        return $this;
    }

    public function willReport(
        string $url,
        string $day,
        int $sessions,
        ?int $engagedSessions = null,
        int $engagementSeconds = 0,
    ): self {
        $this->rows[] = new UnitEngagement(
            $url,
            Carbon::parse($day),
            $sessions,
            $engagedSessions ?? $sessions,
            $engagementSeconds,
        );

        return $this;
    }

    /**
     * Steady traffic that stops engaging — the shape Search Console cannot see.
     */
    public function willDisengage(string $url, string $from, int $days, int $sessions, float $startRate, float $endRate): self
    {
        $start = Carbon::parse($from);
        $step = ($startRate - $endRate) / max(1, $days - 1);

        for ($i = 0; $i < $days; $i++) {
            $rate = $startRate - ($step * $i);

            $this->willReport(
                $url,
                $start->copy()->addDays($i)->toDateString(),
                $sessions,
                (int) round($sessions * $rate),
                (int) round($sessions * $rate * 45),
            );
        }

        return $this;
    }

    /**
     * @param  list<string>  $urls
     * @return list<UnitEngagement>
     */
    public function engagement(Project $project, array $urls, Carbon $from, Carbon $to): array
    {
        $wanted = array_flip($urls);

        return array_values(array_filter(
            $this->rows,
            static fn (UnitEngagement $row): bool => isset($wanted[$row->url])
                && $row->measuredOn->betweenIncluded($from, $to),
        ));
    }
}
