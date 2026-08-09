<?php

declare(strict_types=1);

namespace App\Support\Duty;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidTimeZoneException;

/**
 * When a project's operator is available (§4.3, §11.4).
 *
 * §4.3 does not ask "is somebody around at 10:00". It asks whether somebody
 * will be around for the *next* 60–90 minutes, because the algorithm weighs the
 * speed of replies in the first hour. A window closing in ten minutes therefore
 * does not qualify a slot, and {@see covers()} is written as a containment test
 * rather than a point test for exactly that reason.
 *
 * **An empty configuration means never on duty.** This is the choice worth
 * arguing about, and it is deliberate. The friendly default — "unconfigured
 * means always available" — makes an unanswered onboarding question look
 * identical to round-the-clock cover, and the failure it produces is the engine
 * publishing at 04:00 into a silence and then losing reach for a week because
 * nothing answered. §4.3 says a post nobody can follow is worse than no post,
 * so the safe direction is to publish nothing until somebody says when.
 *
 * Windows crossing midnight are rejected at parse time: an end must be strictly
 * after its start. Allowing them would mean a window belonging to two weekday
 * keys, so every read would have to look at yesterday as well as today, and the
 * saved case is a night shift nobody has asked for. A project that needs one
 * writes two windows and takes the split at midnight — which is what the
 * calendar does anyway.
 *
 * Windows that touch or overlap are merged at parse time, so a day is always a
 * list of disjoint, non-adjacent windows and {@see covers()} can stay a single
 * containment test. `[["09:00","12:00"],["12:00","18:00"]]` therefore means one
 * window from 09:00 to 18:00. Reading them as two separate shifts — "the
 * operator who left at 12:00 is not covering 11:30–12:30" — needs an operator
 * identity that this column does not have: `duty_hours` is one project-level
 * value with no name attached to any range, so the split is an artefact of how
 * somebody typed their availability rather than a fact about who is on. Left
 * unmerged it silently deletes every slot from 10:30 to 12:00 with nothing in
 * the UI to explain why. Merging also makes {@see toArray()} genuinely
 * canonical: two spellings of the same availability now compare equal.
 */
final readonly class DutyHours
{
    /** ISO weekday keys, in order, as they appear in the stored shape. */
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Minutes from local midnight, normalised: day => list of [start, end].
     *
     * Stored as integers rather than as the "HH:MM" strings that came in, so
     * that every comparison is arithmetic and the class has exactly one place
     * where a string becomes a time.
     *
     * @param  array<string, list<array{int, int}>>  $windows
     */
    private function __construct(private array $windows) {}

    /**
     * Read the stored column.
     *
     * Tolerant by contract: unknown keys, malformed pairs, unparseable times
     * and inverted ranges are dropped, and nothing throws. The column is
     * operator-editable JSON, and a project whose duty hours are half-typed
     * should schedule conservatively rather than fail every planning run — the
     * safe direction is already "never on duty", so dropping a bad window
     * cannot make the engine publish anything it otherwise would not.
     *
     * @param  array<array-key, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return new self([]);
        }

        $windows = [];

        foreach (self::DAYS as $day) {
            $ranges = $raw[$day] ?? null;

            if (! is_array($ranges)) {
                continue;
            }

            $parsed = [];

            foreach ($ranges as $range) {
                $pair = self::parseRange($range);

                if ($pair !== null) {
                    $parsed[] = $pair;
                }
            }

            if ($parsed !== []) {
                // Sorted so `toArray()` round-trips in a stable order and two
                // configurations that differ only in the order they were typed
                // compare equal.
                usort($parsed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

                $windows[$day] = self::merge($parsed);
            }
        }

        return new self($windows);
    }

    /**
     * True when the whole of `[from, from + minutes]` sits inside one window.
     *
     * One window, and after {@see fromArray()} has merged everything that
     * touches there is only ever one to be inside: a day is a list of disjoint,
     * non-adjacent windows, so containment in any single one is containment in
     * the day's availability.
     *
     * The end of a window is inclusive — a slot finishing exactly as the window
     * closes is covered, because that is the last moment the requirement of
     * §4.3 is still met rather than the first moment it is not. A slot that
     * finishes exactly at local midnight is covered by a window ending `24:00`
     * for the same reason.
     *
     * The end of the slot is a real instant rather than `start + minutes`
     * seconds-of-day arithmetic, because a local day is not always 86 400
     * seconds long. On the morning the clocks go forward, 00:30 plus 90 minutes
     * is 03:00 local, not 02:00 — and a window closing at 02:00 must refuse it.
     * The arithmetic version said yes, which is precisely the "published while
     * the operator was asleep" failure §4.3 exists to prevent.
     *
     * Never throws, matching {@see fromArray()}: an unparseable timezone reads
     * as not on duty rather than as an exception out of a planning run. The
     * column is validated at both write points, so this is a floor and not an
     * expected path.
     */
    public function covers(CarbonInterface $from, int $minutes, string $timezone): bool
    {
        if ($this->windows === [] || $minutes < 0) {
            return false;
        }

        try {
            $local = CarbonImmutable::instance($from)->setTimezone($timezone);
        } catch (InvalidTimeZoneException) {
            return false;
        }

        // `format('D')` and not a localised day name: Carbon routes `D` through
        // `rawFormat()`, which is the PHP formatter and locale-independent, so
        // this stays `Mon` whatever `app.locale` or `setlocale()` say.
        $day = mb_strtolower($local->format('D'));

        $endSeconds = self::secondsOfDay($local, $local->addMinutes($minutes));

        if ($endSeconds === null) {
            return false;
        }

        $startSeconds = $local->hour * 3_600 + $local->minute * 60 + $local->second;

        foreach ($this->windows[$day] ?? [] as [$windowStart, $windowEnd]) {
            if ($startSeconds >= $windowStart * 60 && $endSeconds <= $windowEnd * 60) {
                return true;
            }
        }

        return false;
    }

    /** True when no usable window survived parsing — that is, never on duty. */
    public function isEmpty(): bool
    {
        return $this->windows === [];
    }

    /**
     * The canonical stored shape: days in week order, times zero-padded.
     *
     * Not necessarily what came in. This is the cleaned version, which is what
     * should be written back so a project's column converges on something a
     * human can read after being edited by one.
     *
     * @return array<string, list<array{string, string}>>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->windows as $day => $ranges) {
            $out[$day] = array_map(
                static fn (array $range): array => [self::format($range[0]), self::format($range[1])],
                $ranges,
            );
        }

        return $out;
    }

    /**
     * Where the slot ends, as seconds from the start day's local midnight.
     *
     * Null when it ends on another day and so cannot be inside any of that
     * day's windows. The one exception is exactly local midnight, which is the
     * `24:00` a window is allowed to end at — 22:30 plus 90 minutes lands on
     * the close of a `["22:30","24:00"]` window rather than outside it.
     *
     * @param  CarbonImmutable  $start  already in the project's timezone
     */
    private static function secondsOfDay(CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        if ($end->toDateString() === $start->toDateString()) {
            return $end->hour * 3_600 + $end->minute * 60 + $end->second;
        }

        if ($end->format('H:i:s.u') === '00:00:00.000000' && $end->toDateString() === $start->addDay()->toDateString()) {
            return 86_400;
        }

        return null;
    }

    /**
     * Ranges that touch or overlap, folded into one.
     *
     * @param  list<array{int, int}>  $sorted  by start
     * @return list<array{int, int}>
     */
    private static function merge(array $sorted): array
    {
        $merged = [];

        foreach ($sorted as $range) {
            $last = array_key_last($merged);

            // `<=` and not `<`: 12:00 touching 12:00 is the same availability
            // written twice, and leaving the seam in place deletes the slots
            // that straddle it.
            if ($last !== null && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    /**
     * One `["09:00", "18:00"]` pair, or null if it is not one.
     *
     * @return array{int, int}|null
     */
    private static function parseRange(mixed $range): ?array
    {
        if (! is_array($range) || ! array_key_exists(0, $range) || ! array_key_exists(1, $range)) {
            return null;
        }

        $start = self::parseTime($range[0]);
        $end = self::parseTime($range[1]);

        if ($start === null || $end === null) {
            return null;
        }

        // Strictly after: a zero-length window covers nothing, and an inverted
        // one is either a typo or a request to cross midnight. Both are dropped
        // rather than repaired, because guessing which produces a schedule
        // nobody wrote.
        if ($end <= $start) {
            return null;
        }

        return [$start, $end];
    }

    /** "HH:MM" as minutes from midnight, or null. */
    private static function parseTime(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        // 24:00 is a legal way to write the end of a day in ISO 8601 and is the
        // only value above 23:59 accepted, so a window may run to midnight
        // without being a window that crosses it.
        if ($minutes > 59 || $hours > 24 || ($hours === 24 && $minutes !== 0)) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    private static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
