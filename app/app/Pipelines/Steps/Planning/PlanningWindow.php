<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Billing\Entitlements;
use App\Enums\BillingStatus;
use App\Models\ArticlePlanningPeriod;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The stretch of days a plan covers.
 *
 * Not "a month starting on the first", which is what this used to be and what
 * made a project set up on the 7th publish nothing until the 1st of the next
 * month — twenty-three days of an empty calendar for somebody who had just
 * signed up. A plan starts *tomorrow*. The month it belongs to is whichever
 * month tomorrow falls in.
 *
 * The exception is the tail of a month: with three days left there is no room
 * for a cadence, so the window rolls to the next month and starts on its first.
 * Worst case the first article is a week out; usually it is tomorrow.
 *
 * Shared by both planning steps that need it, because "how many units" and
 * "on which days" are the same question asked twice and they must not disagree.
 */
final readonly class PlanningWindow
{
    /** Below this many days left, there is no room to space anything out. */
    private const int MINIMUM_DAYS = 7;

    private function __construct(
        /** First of the month this plan is filed under. */
        public Carbon $month,
        public Carbon $start,
        public Carbon $end,
        public ?Carbon $periodStart = null,
        public ?Carbon $periodEnd = null,
        public ?int $articleLimit = null,
        public ?int $pacingLimit = null,
    ) {}

    /**
     * @param  string|null  $requested  an explicit month, if a caller named one
     */
    public static function resolve(?string $requested = null): self
    {
        $tomorrow = Carbon::tomorrow();

        if ($requested !== null) {
            $month = Carbon::parse($requested)->startOfMonth();

            // Honoured, but never backwards: re-planning the current month in
            // the middle of it must not schedule into days that have passed.
            return new self(
                month: $month,
                start: $month->lessThan($tomorrow) ? $tomorrow->copy() : $month->copy(),
                end: $month->copy()->endOfMonth(),
            );
        }

        $endOfThisMonth = $tomorrow->copy()->endOfMonth();

        if ($tomorrow->diffInDays($endOfThisMonth) + 1 >= self::MINIMUM_DAYS) {
            return new self(
                month: $tomorrow->copy()->startOfMonth(),
                start: $tomorrow->copy(),
                end: $endOfThisMonth,
            );
        }

        $next = $tomorrow->copy()->addMonth()->startOfMonth();

        return new self(month: $next->copy(), start: $next->copy(), end: $next->copy()->endOfMonth());
    }

    /** V4 uses confirmed billing instants; calendar months are only display buckets. */
    public static function forProject(Project $project, ?string $requested = null, ?string $expectedPeriod = null): self
    {
        $entitlements = app(Entitlements::class);
        $entitlements->forget($project);
        $entitlement = $entitlements->for($project);
        $subscription = $entitlement->subscription;
        if (($subscription->plan_version ?? 0) < 4) {
            return self::resolve($requested);
        }
        $period = $subscription->period_started_at === null ? null : ArticlePlanningPeriod::acrossProjects()->where('project_id', $project->id)
            ->where('period_started_at', $subscription->period_started_at->copy()->utc())->first();
        $timezone = $period->timezone ?? $project->timezone;
        $start = $subscription->period_started_at?->copy()->setTimezone($timezone);
        $end = ($subscription->status === BillingStatus::Trialing ? $subscription->trial_ends_at : $subscription->period_ends_at)?->copy()->setTimezone($timezone);
        $now = Carbon::now($timezone);
        if ($start === null || $end === null || $start->greaterThan($now) || $end->lessThanOrEqualTo($now) || $end->lessThanOrEqualTo($start)
            || ($expectedPeriod !== null && ! $start->equalTo(Carbon::parse($expectedPeriod)))) {
            throw ValidationException::withMessages(['planning' => 'A current confirmed billing period is required before planning articles.']);
        }
        if ($requested !== null) {
            $month = Carbon::parse($requested, $timezone)->startOfMonth();
            if ($month->greaterThanOrEqualTo($end) || $month->copy()->addMonth()->lessThanOrEqualTo($start)) {
                throw ValidationException::withMessages(['planning' => 'Only the current confirmed billing period can be planned.']);
            }
        }

        $limit = $entitlement->limit('articles');
        $defaultCadence = $entitlement->plan?->weeklyTarget() ?? 7;
        $days = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay());
        $paced = $project->weekly_target >= $defaultCadence ? $limit
            : min($limit ?? PHP_INT_MAX, max(1, (int) round($project->weekly_target * $days / 7)));

        return new self($start->copy()->startOfMonth(), $start->copy()->addDay()->startOfDay(), $end->copy(), $start, $end, $limit, $paced);
    }

    /** @return array<string, string> */
    public function input(): array
    {
        return ['month' => $this->month->toDateString(), ...($this->periodStart === null ? [] : ['article_period_started_at' => $this->periodStart->toIso8601String()])];
    }

    /**
     * Fixed allowance spread across the actual period, with at most two slots
     * per local day (30 articles must also fit a 28-day billing period).
     * Elapsed slots are not moved into a catch-up burst.
     *
     * @return list<Carbon>
     */
    public function publicationSlots(): array
    {
        if ($this->periodStart === null || $this->periodEnd === null || ($this->pacingLimit ?? 0) <= 0) {
            return [];
        }
        $morning = [];
        $afternoon = [];
        for ($day = $this->start->copy(); $day->lessThan($this->periodEnd); $day->addDay()) {
            $at = $day->copy()->setTime(9, 0);
            if ($at->lessThan($this->periodEnd)) {
                $morning[] = $at;
            }
            $at = $day->copy()->setTime(15, 0);
            if ($at->lessThan($this->periodEnd)) {
                $afternoon[] = $at;
            }
        }
        $count = min($this->pacingLimit, count($morning) + count($afternoon));
        $slots = self::spread($morning, min($count, count($morning)));
        $slots = [...$slots, ...self::spread($afternoon, max(0, $count - count($morning)))];
        usort($slots, fn (Carbon $a, Carbon $b): int => $a->getTimestamp() <=> $b->getTimestamp());
        $tomorrow = Carbon::tomorrow($this->periodStart->getTimezone());

        return array_values(array_filter($slots, fn (Carbon $slot): bool => $slot->greaterThanOrEqualTo($tomorrow)));
    }

    /**
     * @param  list<Carbon>  $slots
     * @return list<Carbon>
     */
    public static function spread(array $slots, int $count): array
    {
        $out = [];
        for ($i = 0; $i < min($count, count($slots)); $i++) {
            $out[] = $slots[(int) floor($i * count($slots) / $count)];
        }

        return $out;
    }

    /** Days the window covers, both ends included. */
    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /**
     * How many units fit, at this project's cadence.
     *
     * Proportional to the window rather than to a calendar month: half a month
     * left means half a month's articles, not a month's worth crammed into it.
     */
    public function capacityFor(int $weeklyTarget): int
    {
        return max(1, (int) round($weeklyTarget * ($this->days() / 7)));
    }

    /**
     * The publishing dates, spread across the window.
     *
     * Evenly rather than "every Tuesday": §1 names publishing cadence as a
     * scaled-content risk, and a burst of twelve articles on one day is the
     * shape that reads as automated whatever the frequency averages out to.
     *
     * @return list<Carbon>
     */
    public function dates(int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $days = $this->days();
        $stride = $days / $count;
        $dates = [];

        for ($i = 0; $i < $count; $i++) {
            $offset = min($days - 1, (int) floor($i * $stride));
            $dates[] = $this->start->copy()->addDays($offset);
        }

        return $dates;
    }
}
