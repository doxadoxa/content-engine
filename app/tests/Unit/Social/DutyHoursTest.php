<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Support\Duty\DutyHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §4.3's окно присутствия, which is a containment test and not a point test.
 *
 * The distinction is the whole class. "Is somebody around at 10:00" is easy and
 * wrong; the question §4.3 actually asks is whether somebody will be around for
 * the next 60–90 minutes, because the algorithm weighs the speed of replies in
 * the first hour. A window that closes in ten minutes therefore qualifies
 * nothing, and the tests below are mostly about the boundary where that stops
 * being obvious.
 */
final class DutyHoursTest extends TestCase
{
    #[Test]
    public function a_window_is_read_in_the_projects_own_timezone(): void
    {
        // A Wednesday. Lisbon is on WEST in July, so an instant of 08:30 UTC is
        // 09:30 for the operator and inside the morning shift.
        $hours = DutyHours::fromArray(['wed' => [['09:00', '13:00']]]);

        $insideLocalMorning = CarbonImmutable::parse('2026-07-01 08:30', 'UTC');
        $beforeLocalMorning = CarbonImmutable::parse('2026-07-01 07:00', 'UTC');

        $this->assertSame('Wednesday', $insideLocalMorning->format('l'));

        $this->assertTrue($hours->covers($insideLocalMorning, 90, 'Europe/Lisbon'));

        // 08:00 local — an hour before anybody is there. Reading the raw UTC
        // clock instead would call this 07:00 and also refuse it, which is the
        // trap: the same test passes for the wrong reason unless the offset is
        // big enough to change the answer.
        $this->assertFalse($hours->covers($beforeLocalMorning, 90, 'Europe/Lisbon'));

        // …and the same instant in a zone two hours ahead lands at 11:30, which
        // this window also covers. Same moment, different project, same rule.
        $this->assertTrue($hours->covers($insideLocalMorning, 90, 'Europe/Kyiv'));
    }

    #[Test]
    public function a_slot_that_runs_past_the_end_of_its_window_is_not_covered(): void
    {
        $hours = DutyHours::fromArray(['wed' => [['09:00', '13:00']]]);

        // Starts on duty, finishes after everyone has gone. This is the case
        // §4.3 is written against — a post at 17:55 into a window closing at
        // 18:00 is office hours doing the same damage as 03:00.
        $this->assertFalse(
            $hours->covers(CarbonImmutable::parse('2026-07-01 12:30', 'Europe/Lisbon'), 60, 'Europe/Lisbon'),
        );
    }

    #[Test]
    public function a_slot_finishing_exactly_as_the_window_closes_is_covered(): void
    {
        $hours = DutyHours::fromArray(['wed' => [['09:00', '13:00']]]);

        // The last moment the requirement is still met, rather than the first
        // moment it is not.
        $this->assertTrue(
            $hours->covers(CarbonImmutable::parse('2026-07-01 12:00', 'Europe/Lisbon'), 60, 'Europe/Lisbon'),
        );
    }

    #[Test]
    public function windows_that_touch_are_one_window(): void
    {
        $hours = DutyHours::fromArray(['wed' => [['09:00', '13:00'], ['13:00', '18:00']]]);

        // Reading these as two shifts needs an operator identity the column
        // does not have: `duty_hours` is one project-level value with no name
        // attached to any range. Left unmerged it deletes every slot from 11:30
        // to 13:00 with nothing in the UI to say why.
        $this->assertTrue(
            $hours->covers(CarbonImmutable::parse('2026-07-01 12:45', 'Europe/Lisbon'), 30, 'Europe/Lisbon'),
        );

        // …and the canonical form says so, so an operator can see it happened.
        $this->assertSame(['wed' => [['09:00', '18:00']]], $hours->toArray());
    }

    #[Test]
    public function overlapping_and_out_of_order_windows_merge_into_one(): void
    {
        $hours = DutyHours::fromArray([
            'mon' => [
                ['14:00', '18:00'],
                ['09:00', '15:00'],  // overlaps the first
                ['20:00', '22:00'],  // a real gap, so it stays its own window
                ['21:00', '21:30'],  // wholly inside the one before it
            ],
        ]);

        $this->assertSame(
            ['mon' => [['09:00', '18:00'], ['20:00', '22:00']]],
            $hours->toArray(),
        );

        // A window swallowed by a longer one must not shorten it: 21:45 is
        // still inside 20:00–22:00 even though 21:00–21:30 ended before it.
        $this->assertTrue(
            $hours->covers(CarbonImmutable::parse('2026-07-06 21:45', 'Europe/Lisbon'), 15, 'Europe/Lisbon'),
        );

        // The gap between the two surviving windows is still a gap.
        $this->assertFalse(
            $hours->covers(CarbonImmutable::parse('2026-07-06 19:00', 'Europe/Lisbon'), 30, 'Europe/Lisbon'),
        );
    }

    #[Test]
    public function a_window_belongs_to_its_own_weekday_and_no_other(): void
    {
        $hours = DutyHours::fromArray(['mon' => [['09:00', '18:00']]]);

        $this->assertTrue(
            $hours->covers(CarbonImmutable::parse('2026-07-06 10:00', 'Europe/Lisbon'), 90, 'Europe/Lisbon'),
        );

        // Same clock time, two days later. A day key that leaked across days
        // would be indistinguishable from a correct answer for six sevenths of
        // the week, which is exactly how it survives review.
        $this->assertFalse(
            $hours->covers(CarbonImmutable::parse('2026-07-08 10:00', 'Europe/Lisbon'), 90, 'Europe/Lisbon'),
        );
    }

    #[Test]
    public function the_weekday_is_the_projects_own_and_not_the_instants(): void
    {
        // 23:30 UTC on a Sunday is 02:30 on Monday in Kyiv, so it is the
        // Monday window that has to answer for it. Reading the weekday off the
        // UTC instant would look for a Sunday window and find nothing.
        $hours = DutyHours::fromArray(['mon' => [['02:00', '06:00']]]);

        $sundayNight = CarbonImmutable::parse('2026-07-05 23:30', 'UTC');

        $this->assertSame('Sunday', $sundayNight->format('l'));

        $this->assertTrue($hours->covers($sundayNight, 90, 'Europe/Kyiv'));

        // And the mirror: a `sun` window must not pick the same instant up.
        $this->assertFalse(
            DutyHours::fromArray(['sun' => [['02:00', '06:00']]])->covers($sundayNight, 90, 'Europe/Kyiv'),
        );
    }

    #[Test]
    public function nine_in_the_morning_means_nine_in_the_morning_in_both_halves_of_the_year(): void
    {
        // Lisbon is +00 in January and +01 in July. A window is wall-clock
        // local time, so the same "09:00–13:00" has to mean the operator's
        // nine o'clock in both — stored in UTC it would move twice a year.
        $hours = DutyHours::fromArray(['wed' => [['09:00', '13:00']]]);

        $winter = CarbonImmutable::parse('2026-01-07 09:30', 'UTC');
        $summer = CarbonImmutable::parse('2026-07-01 08:30', 'UTC');

        $this->assertTrue($hours->covers($winter, 90, 'Europe/Lisbon'));
        $this->assertTrue($hours->covers($summer, 90, 'Europe/Lisbon'));

        // An hour earlier in winter is 08:30 local — before anybody is in.
        // This is the assertion that fails if the offset is applied backwards.
        $this->assertFalse(
            $hours->covers(CarbonImmutable::parse('2026-01-07 08:30', 'UTC'), 90, 'Europe/Lisbon'),
        );
    }

    #[Test]
    public function a_slot_that_runs_through_the_spring_forward_gap_is_not_covered(): void
    {
        // Europe/Lisbon, 2026-03-29: a 23-hour day, 01:00 jumps to 02:00. A
        // slot at 00:30 lasting 90 minutes ends at 03:00 local, an hour after
        // a window closing at 02:00 has shut. Adding `minutes * 60` to the
        // seconds-since-midnight says 02:00 and calls it covered, which is the
        // "published while the operator was asleep" failure §4.3 exists to
        // prevent — so the end has to be a real instant.
        $hours = DutyHours::fromArray(['sun' => [['00:00', '02:00']]]);

        $before = CarbonImmutable::parse('2026-03-29 00:30', 'Europe/Lisbon');

        $this->assertSame('Sunday', $before->format('l'));
        $this->assertSame('2026-03-29 03:00', $before->addMinutes(90)->format('Y-m-d H:i'));

        $this->assertFalse($hours->covers($before, 90, 'Europe/Lisbon'));

        // 30 minutes still fits: the gap is what disqualifies the slot, not
        // the date.
        $this->assertTrue($hours->covers($before, 30, 'Europe/Lisbon'));
    }

    #[Test]
    public function a_window_neither_shifts_nor_vanishes_on_a_23_or_25_hour_day(): void
    {
        // Both Lisbon transitions in 2026 fall on a Sunday: 29 March loses an
        // hour, 25 October gains one. A window well clear of the transition
        // must be exactly where the operator wrote it on both days.
        $hours = DutyHours::fromArray(['sun' => [['09:00', '13:00']]]);

        foreach (['2026-03-29', '2026-10-25'] as $date) {
            $this->assertTrue(
                $hours->covers(CarbonImmutable::parse("{$date} 09:30", 'Europe/Lisbon'), 90, 'Europe/Lisbon'),
                "09:30 local is inside the morning window on {$date}.",
            );

            $this->assertFalse(
                $hours->covers(CarbonImmutable::parse("{$date} 08:00", 'Europe/Lisbon'), 90, 'Europe/Lisbon'),
                "08:00 local is before the morning window on {$date}.",
            );

            $this->assertFalse(
                $hours->covers(CarbonImmutable::parse("{$date} 12:30", 'Europe/Lisbon'), 90, 'Europe/Lisbon'),
                "A slot from 12:30 outlasts a window closing at 13:00 on {$date}.",
            );
        }
    }

    #[Test]
    public function a_negative_slot_length_covers_nothing(): void
    {
        // Nonsense in, "not on duty" out. The alternative — treating it as a
        // slot that ends before it starts — makes every window in the week
        // trivially satisfiable.
        $hours = DutyHours::fromArray(['wed' => [['09:00', '18:00']]]);

        $this->assertFalse(
            $hours->covers(CarbonImmutable::parse('2026-07-01 12:00', 'Europe/Lisbon'), -60, 'Europe/Lisbon'),
        );

        $this->assertTrue(
            $hours->covers(CarbonImmutable::parse('2026-07-01 12:00', 'Europe/Lisbon'), 0, 'Europe/Lisbon'),
        );
    }

    #[Test]
    public function a_slot_finishing_at_midnight_fits_a_window_ending_at_24_00(): void
    {
        $hours = DutyHours::fromArray(['wed' => [['20:00', '24:00']]]);

        // 22:30 plus 90 minutes lands on the close of the window, not past it.
        // The end instant is the next calendar day, so a plain same-day check
        // would refuse it and make `24:00` unreachable.
        $this->assertTrue(
            $hours->covers(CarbonImmutable::parse('2026-07-01 22:30', 'Europe/Lisbon'), 90, 'Europe/Lisbon'),
        );

        // A minute over midnight is Thursday's problem, and Thursday has no
        // window.
        $this->assertFalse(
            $hours->covers(CarbonImmutable::parse('2026-07-01 22:30', 'Europe/Lisbon'), 91, 'Europe/Lisbon'),
        );
    }

    #[Test]
    public function an_unusable_timezone_reads_as_off_duty_rather_than_throwing(): void
    {
        // Unreachable while `projects.timezone` is validated at both write
        // points, but the class promises never to throw, and a planning run is
        // the wrong place to discover otherwise.
        $hours = DutyHours::fromArray(['wed' => [['09:00', '18:00']]]);

        $this->assertFalse(
            $hours->covers(CarbonImmutable::parse('2026-07-01 12:00', 'UTC'), 60, 'Nowhere/Atall'),
        );
    }

    #[Test]
    public function nothing_configured_means_never_on_duty(): void
    {
        $instant = CarbonImmutable::parse('2026-07-01 10:00', 'Europe/Lisbon');

        foreach ([null, [], ['wed' => []], ['wed' => 'all day']] as $raw) {
            $hours = DutyHours::fromArray($raw);

            $this->assertTrue($hours->isEmpty());

            // The friendly default — unconfigured means always available —
            // makes an unanswered onboarding question look identical to
            // round-the-clock cover, and pays for it at 04:00.
            $this->assertFalse($hours->covers($instant, 90, 'Europe/Lisbon'));
        }
    }

    #[Test]
    public function malformed_windows_are_dropped_rather_than_thrown_on(): void
    {
        $hours = DutyHours::fromArray([
            'mon' => [
                ['09:00'],              // one end of a range is not a range
                ['not a time', '18:00'],
                ['18:00', '09:00'],     // inverted, or a request to cross midnight
                ['10:00', '10:00'],     // zero length covers nothing
                ['99:00', '99:30'],
                'the afternoon',
                ['14:00', '18:00'],     // the only usable one
            ],
            'tue' => 'whenever',
            'funday' => [['09:00', '10:00']],
        ]);

        // The column is operator-editable JSON. A half-typed configuration
        // should schedule conservatively rather than fail every planning run,
        // and since the safe direction is already "never on duty", dropping a
        // bad window cannot make the engine publish anything it otherwise
        // would not.
        $this->assertSame(['mon' => [['14:00', '18:00']]], $hours->toArray());
    }

    #[Test]
    public function to_array_round_trips_in_a_stable_order(): void
    {
        $hours = DutyHours::fromArray([
            'sat' => [['10:00', '12:00']],
            'mon' => [['14:00', '18:00'], ['9:00', '13:00']],
        ]);

        $canonical = [
            'mon' => [['09:00', '13:00'], ['14:00', '18:00']],
            'sat' => [['10:00', '12:00']],
        ];

        // Days in week order, ranges by start, hours zero-padded — so a column
        // written back converges on something a human can read, and two
        // configurations differing only in typing order compare equal.
        $this->assertSame($canonical, $hours->toArray());
        $this->assertSame($canonical, DutyHours::fromArray($hours->toArray())->toArray());
    }

    #[Test]
    public function midnight_is_a_legal_end_and_half_past_midnight_is_not(): void
    {
        // 24:00 is how ISO 8601 writes the end of a day, so a window may run to
        // midnight without being a window that crosses it.
        $this->assertSame(
            ['mon' => [['22:00', '24:00']]],
            DutyHours::fromArray(['mon' => [['22:00', '24:00']]])->toArray(),
        );

        $this->assertTrue(DutyHours::fromArray(['mon' => [['22:00', '24:30']]])->isEmpty());
        $this->assertTrue(DutyHours::fromArray(['mon' => [['24:30', '25:00']]])->isEmpty());
    }
}
