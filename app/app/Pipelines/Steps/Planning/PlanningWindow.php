<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use Illuminate\Support\Carbon;

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
