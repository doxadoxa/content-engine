<?php

declare(strict_types=1);

namespace App\Feedback;

/**
 * One window's audience for a whole project, split by channel (§6).
 *
 * Answered by one GA4 report, because splitting the call would multiply the
 * quota for numbers that have to agree with each other anyway.
 *
 * **`total` is not the sum of the other two, and that is deliberate.** Direct
 * plus referral leaves out organic search and paid, which on most of these
 * projects is most of the traffic. A share computed against those two alone
 * would flatter the channel by a factor, and would move whenever search moved.
 * The denominator is carried explicitly so a reader never has to invent one.
 */
final readonly class ProjectAudience
{
    public function __construct(
        /** Every session in the window, whatever brought it. The denominator. */
        public int $totalSessions,
        /** No referrer at all: they knew where they were going. */
        public int $directSessions,
        /** Somebody else's link, which is presence somebody else granted us. */
        public int $referralSessions,
        /** What it is all for (§6), across every channel. */
        public int $conversions,
    ) {}
}
