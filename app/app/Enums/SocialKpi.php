<?php

declare(strict_types=1);

namespace App\Enums;

use App\Http\Controllers\DashboardController;

/**
 * The one thing a month of social is trying to move.
 *
 * **One, not a scorecard.** Three simultaneous goals is arithmetically the same
 * as none: every idea can be argued to serve at least one of them, so nothing
 * is ever refused and the number on the header stops meaning anything. Forcing
 * the choice is what gives the Overview a figure worth reading, and it is the
 * single cheapest thing the reference product does.
 *
 * Each case declares the measurement it needs. That declaration is the whole
 * reason this is an enum rather than three strings: a KPI the engine cannot
 * source is a number that renders as a confident zero, and a confident zero is
 * worse than an empty state — the same argument
 * {@see DashboardController::stats()} makes about cards
 * that read "—" forever.
 */
enum SocialKpi: string
{
    /** People who chose to keep hearing from the brand. */
    case Followers = 'followers';

    /** How many were shown it at all. */
    case Reach = 'reach';

    /** How many did something about it. */
    case Engagement = 'engagement';

    public function label(): string
    {
        return match ($this) {
            self::Followers => 'Grow followers',
            self::Reach => 'Grow reach',
            self::Engagement => 'Grow engagement',
        };
    }

    /**
     * What the operator is actually promising to move.
     *
     * Written as the unit rather than the goal, because the target field beside
     * it is a number and "1200 followers" has to read as a quantity of
     * something.
     */
    public function unit(): string
    {
        return match ($this) {
            self::Followers => 'followers',
            self::Reach => 'accounts reached',
            self::Engagement => 'interactions',
        };
    }

    /**
     * The connection that can supply this measurement, and nothing else.
     *
     * Deliberately not a boolean. "Is this available" is the question the
     * screen asks, but "what would make it available" is the question the
     * operator needs answered, and a padlock with no named precondition is the
     * failure this whole release is a reaction to — see the Meta connection
     * argument in `studio-rethink-spec.md` §6.
     */
    public function requires(): string
    {
        return match ($this) {
            self::Followers => 'A connected account that reports its follower count.',
            self::Reach => 'A connected account that reports per-post impressions.',
            self::Engagement => 'A connected account that reports per-post interactions.',
        };
    }

    /**
     * Whether any channel of this project can report it today.
     *
     * Every case answers the same way for now, and that is not laziness: no
     * publisher in this engine reads insights back yet. Threads publishes and
     * the webhook relay posts to a receiver; neither returns a follower count,
     * an impression or a like. So all three are locked, the screen says which
     * connection would unlock each, and the two figures the Overview *can*
     * source — cadence against plan, and the week — carry the header until one
     * of them can.
     *
     * The method exists rather than the answer being inlined as `false` so that
     * the day a publisher does report insights, one match arm changes and every
     * screen that asks is correct at once.
     *
     * @param  list<string>  $reporting  channel types that report insights back
     */
    public function isMeasurableBy(array $reporting): bool
    {
        return match ($this) {
            self::Followers, self::Reach, self::Engagement => $reporting !== [],
        };
    }
}
