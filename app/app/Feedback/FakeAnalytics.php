<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Contracts\AnalyticsGateway;
use App\Models\Project;
use Illuminate\Support\Carbon;

/** Analytics for the suite. */
class FakeAnalytics implements AnalyticsGateway
{
    /** @var list<UnitEngagement> */
    private array $rows = [];

    /**
     * Channel splits by day: `Y-m-d` => the seven figures of {@see ProjectAudience}.
     *
     * @var array<string, ProjectAudience>
     */
    private array $audience = [];

    private bool $configured = true;

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
     * A day's channel split, as GA4 would have added it up.
     *
     * `total` is asked for separately rather than summed from the parts,
     * because that is what the real report answers and because a test of §6's
     * social *share* needs a denominator larger than the three named channels
     * — otherwise the share is 100% and the assertion proves nothing.
     */
    public function willSee(
        string $day,
        int $total,
        int $direct = 0,
        int $referral = 0,
        int $social = 0,
        ?int $socialEngaged = null,
        int $socialSeconds = 0,
        int $conversions = 0,
    ): self {
        $this->audience[Carbon::parse($day)->toDateString()] = new ProjectAudience(
            totalSessions: $total,
            directSessions: $direct,
            referralSessions: $referral,
            socialSessions: $social,
            socialEngagedSessions: $socialEngaged ?? $social,
            socialEngagementSeconds: $socialSeconds,
            conversions: $conversions,
        );

        return $this;
    }

    /**
     * The window's channel split, summed over the scripted days.
     *
     * Null when unconfigured — "not measured", which is the distinction the
     * daily row of §6 exists to keep.
     */
    public function audience(Project $project, Carbon $from, Carbon $to): ?ProjectAudience
    {
        if (! $this->configured) {
            return null;
        }

        $days = array_filter(
            $this->audience,
            static fn (string $day): bool => Carbon::parse($day)->betweenIncluded($from, $to),
            ARRAY_FILTER_USE_KEY,
        );

        $sum = static fn (callable $of): int => array_sum(array_map($of, $days));

        return new ProjectAudience(
            totalSessions: $sum(static fn (ProjectAudience $a): int => $a->totalSessions),
            directSessions: $sum(static fn (ProjectAudience $a): int => $a->directSessions),
            referralSessions: $sum(static fn (ProjectAudience $a): int => $a->referralSessions),
            socialSessions: $sum(static fn (ProjectAudience $a): int => $a->socialSessions),
            socialEngagedSessions: $sum(static fn (ProjectAudience $a): int => $a->socialEngagedSessions),
            socialEngagementSeconds: $sum(static fn (ProjectAudience $a): int => $a->socialEngagementSeconds),
            conversions: $sum(static fn (ProjectAudience $a): int => $a->conversions),
        );
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
