<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Models\ProjectState;

/**
 * One day of the channel's own health (§6).
 *
 * §6 lists "подписчики и reply rate" as the signal that is about the channel
 * rather than about the site, and §4.3 makes the second half of it the input
 * the governor throttles on. Everything here is the numerator or the
 * denominator of something a person will read on a Monday.
 *
 * **Every field is nullable, and each one independently.** The permission is
 * granted per metric, the endpoint answers per metric, and Meta has renamed
 * metrics before — so "the call succeeded" and "this number arrived" are
 * different facts and the type has to be able to say so. They used to be
 * non-null with a `?? 0` at the constructor, which turned a missing `replies`
 * into a measured zero beside a real `views`: a reply rate of exactly 0.0 that
 * {@see ProjectState::replyRate()} would report as real, the
 * governor would average into its trailing window, and §4.3 would throttle the
 * project on. Being wrong here costs a healthy account half its cadence for a
 * month, which is the whole reason {@see ThreadsInsights} returns nulls rather
 * than throwing.
 *
 * `followers` was nullable first and for a narrower reason worth keeping
 * separate: the platform serves it from a different metric with a different
 * permission and no history at all — it is a lifetime total, so a day's row can
 * hold the count as of the read and nothing better. A day where the post
 * metrics arrived and the follower count did not is a real outcome and not a
 * failed read.
 */
final readonly class ThreadsChannelHealth
{
    public function __construct(
        public ?int $followers,
        /** The platform calls it `views`; the column and §6 call it impressions. */
        public ?int $impressions,
        public ?int $replies,
        public ?int $likes,
        public ?int $reposts,
        public ?int $quotes,
    ) {}
}
