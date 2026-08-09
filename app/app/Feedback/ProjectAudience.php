<?php

declare(strict_types=1);

namespace App\Feedback;

/**
 * One window's audience for a whole project, split by channel (§6).
 *
 * Three of §6's six signals at once, because GA4 answers them in one report and
 * splitting the call would triple the quota for numbers that have to agree with
 * each other anyway.
 *
 * **`total` is not the sum of the other three, and that is deliberate.** §6
 * asks for the *share* of social traffic; direct plus referral plus social
 * leaves out organic search and paid, which on most of these projects is most
 * of the traffic. A share computed against those three alone would flatter the
 * channel by a factor, and would move whenever search moved. The denominator is
 * carried explicitly so the digest never has to invent one.
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
        /** Organic and paid social together — the channel this phase is about. */
        public int $socialSessions,
        /** Of those, the ones GA4 counts as engaged: stayed, scrolled, or went on. */
        public int $socialEngagedSessions,
        /** Seconds of engagement across the social sessions. */
        public int $socialEngagementSeconds,
        /** What it is all for (§6), across every channel. */
        public int $conversions,
    ) {}
}
