<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Models\Project;
use App\Models\ProjectState;
use App\Social\Governor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What changed about the project, as a shape rather than a reading (§6, §7).
 *
 * §7's third line asks for "что изменилось в состоянии проекта: бренд-спрос,
 * прямой трафик, reply rate", and the word that does the work is *изменилось*.
 * `project_states` holds one row a day precisely so that these three are a
 * direction and not a number, and a summary that printed today's figure would
 * be answering a question nobody asked — an operator cannot tell whether 412
 * brand impressions is good news without yesterday's 380 beside it.
 *
 * **A day nobody measured is a hole, never a zero.** Every vendor column on
 * {@see ProjectState} is nullable for that reason, and the nulls survive all
 * the way to the screen: they are dropped from the averages, counted in
 * `measured_days`, and left as null in `points` so the line breaks instead of
 * dipping to the floor. A metric with no measured day at all reports
 * `measured: false` and a null value, which the screen renders as "not
 * measured". The failure this prevents is the one §6 was written against: a
 * gateway that was never connected looking exactly like an audience that left.
 *
 * **Reply rate is pooled, not averaged.** {@see Governor} argues this at
 * length and this class must not disagree with it: averaging daily rates gives
 * a day with three impressions the same vote as a day with thirty thousand.
 * The daily rates are still what `points` draws, because that is the shape; the
 * headline is total replies over total impressions, which is the number the
 * governor throttles on.
 */
final class ProjectStateTrend
{
    /**
     * How many days each half of the comparison covers.
     *
     * A fortnight against the fortnight before it. Shorter than the governor's
     * 28-day trailing window on purpose, because the two answer different
     * questions: the governor is deciding whether to cut a project's frequency
     * and wants a slow number, while this line is answering "what changed this
     * week" for somebody with five minutes. Fourteen points is also as much
     * shape as a phone can draw and still be read, and §7 makes the screen
     * mobile by default.
     */
    public const int WINDOW_DAYS = 14;

    /**
     * The three signals §7 names, each with both windows and its own series.
     *
     * @return list<array<string, mixed>>
     */
    public function for(Project $project, ?CarbonInterface $now = null): array
    {
        $today = CarbonImmutable::instance($now ?? now())
            ->setTimezone($project->timezone)
            ->startOfDay();

        $rows = $this->rows($project, $today);

        return [
            $this->metric(
                key: 'brand_demand',
                label: 'Brand demand',
                unit: 'per_day',
                note: 'Search Console impressions for queries carrying the brand name — people looking for us '
                    .'by name, which is what presence means (§6).',
                rows: $rows,
                today: $today,
                read: static fn (ProjectState $day): ?float => $day->brand_impressions === null
                    ? null
                    : (float) $day->brand_impressions,
                aggregate: fn (array $days): ?float => $this->mean($days, static fn (ProjectState $day): ?float => $day->brand_impressions === null
                    ? null
                    : (float) $day->brand_impressions),
            ),
            $this->metric(
                key: 'direct_traffic',
                label: 'Direct traffic',
                unit: 'per_day',
                note: 'GA4 sessions that arrived without asking Google — the half of §6 a search report cannot see.',
                rows: $rows,
                today: $today,
                read: static fn (ProjectState $day): ?float => $day->direct_sessions === null
                    ? null
                    : (float) $day->direct_sessions,
                aggregate: fn (array $days): ?float => $this->mean($days, static fn (ProjectState $day): ?float => $day->direct_sessions === null
                    ? null
                    : (float) $day->direct_sessions),
            ),
            $this->metric(
                key: 'reply_rate',
                label: 'Reply rate',
                unit: 'ratio',
                note: 'Replies per impression on the channel. The governor of §4.3 cuts the week\'s frequency '
                    .'when this falls below the threshold, so it is the number that decides how much we post.',
                rows: $rows,
                today: $today,
                read: static fn (ProjectState $day): ?float => $day->replyRate(),
                aggregate: $this->pooledReplyRate(...),
            ),
        ];
    }

    /**
     * One signal over both windows.
     *
     * `read` produces the day's own value and is what the series is drawn from;
     * `aggregate` produces a window's headline and is separate because the two
     * are not the same arithmetic for every metric — see the class docblock on
     * pooling.
     *
     * @param  Collection<string, ProjectState>  $rows
     * @param  callable(ProjectState): (float|null)  $read
     * @param  callable(list<ProjectState>): (float|null)  $aggregate
     * @return array<string, mixed>
     */
    private function metric(
        string $key,
        string $label,
        string $unit,
        string $note,
        Collection $rows,
        CarbonImmutable $today,
        callable $read,
        callable $aggregate,
    ): array {
        /** @var list<array{day: string, value: float|null}> $points */
        $points = [];
        /** @var list<ProjectState> $current */
        $current = [];
        /** @var list<ProjectState> $previous */
        $previous = [];
        $measuredDays = 0;

        // Ending yesterday, never today. {@see ProjectStateCapture} refuses to
        // write the current day — "a partial day filed as a whole one is a
        // trough in the trend that never happened" — so a window that reached
        // today would spend every one of its fourteen slots reporting thirteen
        // measured days and one permanent hole.
        for ($back = self::WINDOW_DAYS * 2; $back >= 1; $back--) {
            $day = $today->subDays($back)->toDateString();
            $row = $rows->get($day);
            $recent = $back <= self::WINDOW_DAYS;

            if ($recent) {
                $value = $row instanceof ProjectState ? $read($row) : null;
                $points[] = ['day' => $day, 'value' => $value];

                if ($value !== null) {
                    $measuredDays++;
                }
            }

            if (! $row instanceof ProjectState) {
                continue;
            }

            if ($recent) {
                $current[] = $row;
            } else {
                $previous[] = $row;
            }
        }

        $now = $aggregate($current);
        $before = $aggregate($previous);

        return [
            'key' => $key,
            'label' => $label,
            'unit' => $unit,
            'note' => $note,
            'window_days' => self::WINDOW_DAYS,
            // The flag the screen branches on. False is "nobody measured this",
            // which is a different fact from a measured zero and must not be
            // rendered as one.
            'measured' => $now !== null,
            'measured_days' => $measuredDays,
            'current' => $now,
            'previous' => $before,
            'change' => $this->change($now, $before),
            'direction' => $this->direction($now, $before),
            'points' => $points,
        ];
    }

    /**
     * The fortnight-on-fortnight move, as a fraction, or null when it has none.
     *
     * Null rather than infinity when the earlier window was zero. Going from no
     * brand impressions to some is real news and the direction says so, but
     * there is no percentage in it, and "+∞%" on a phone reads as a bug.
     */
    private function change(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || $previous <= 0.0) {
            return null;
        }

        return ($current - $previous) / $previous;
    }

    /**
     * Up, down, flat — or null, which means the comparison could not be made.
     *
     * A missing half is not "flat". Flat is a measurement that did not move;
     * null is two windows that cannot be compared because one of them was never
     * read, and the screen says so in words rather than drawing a level line.
     */
    private function direction(?float $current, ?float $previous): ?string
    {
        if ($current === null || $previous === null) {
            return null;
        }

        // A two-percent band, so that sampling noise in GA4 does not present
        // itself as a trend every single day.
        if ($previous > 0.0 && abs($current - $previous) / $previous < 0.02) {
            return 'flat';
        }

        if ($current === $previous) {
            return 'flat';
        }

        return $current > $previous ? 'up' : 'down';
    }

    /**
     * The mean over the days that were measured, ignoring the ones that were
     * not.
     *
     * A mean rather than a total, because the two windows rarely hold the same
     * number of measured days — a gateway connected halfway through would make
     * a sum look like growth that is only more measurement.
     *
     * @param  list<ProjectState>  $days
     * @param  callable(ProjectState): (float|null)  $read
     */
    private function mean(array $days, callable $read): ?float
    {
        $total = 0.0;
        $counted = 0;

        foreach ($days as $day) {
            $value = $read($day);

            if ($value === null) {
                continue;
            }

            $total += $value;
            $counted++;
        }

        return $counted === 0 ? null : $total / $counted;
    }

    /**
     * Total replies over total impressions, on the days that carried a usable
     * rate.
     *
     * {@see ProjectState::replyRate()} decides which days those are — it
     * already refuses a missing denominator, a negative one and a rate above 1
     * — and re-deriving that from the raw columns here is how two definitions
     * of one number start disagreeing.
     *
     * @param  list<ProjectState>  $days
     */
    private function pooledReplyRate(array $days): ?float
    {
        $replies = 0;
        $impressions = 0;

        foreach ($days as $day) {
            if ($day->replyRate() === null) {
                continue;
            }

            $replies += $day->post_replies ?? 0;
            $impressions += $day->post_impressions ?? 0;
        }

        return $impressions <= 0 ? null : $replies / $impressions;
    }

    /**
     * Both windows in one query, keyed by the local calendar day.
     *
     * `acrossProjects()` with an explicit `project_id`, on the same reasoning
     * as {@see Governor}: this is also reached from a console context with no
     * current tenant, and a scope that fails closed would report a healthy
     * project as never measured.
     *
     * @return Collection<string, ProjectState>
     */
    private function rows(Project $project, CarbonImmutable $today): Collection
    {
        return ProjectState::acrossProjects()
            ->where('project_id', $project->getKey())
            ->whereBetween('captured_on', [
                $today->subDays(self::WINDOW_DAYS * 2)->toDateString(),
                $today->subDay()->toDateString(),
            ])
            ->orderBy('captured_on')
            ->get()
            ->keyBy(static fn (ProjectState $row): string => $row->captured_on->toDateString());
    }
}
