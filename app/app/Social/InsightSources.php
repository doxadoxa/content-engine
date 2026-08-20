<?php

declare(strict_types=1);

namespace App\Social;

use App\Http\Controllers\ChannelController;
use App\Models\Channel;

/**
 * Which of this project's channels can report a number back.
 *
 * Publishing and measuring are different capabilities and this engine has only
 * the first. Threads has a first-party publisher; the webhook relay POSTs to a
 * receiver the project owns; the pull API hands units to a site generator. Not
 * one of them returns a follower count, an impression or a like, so a KPI
 * expressed in those units cannot be sourced today however many channels are
 * connected.
 *
 * That is worth a class rather than a hard-coded `false` for one reason: the
 * screen has to name the missing thing. A padlock with no precondition beside
 * it is the exact failure the Meta connection flow taught us to avoid — a
 * successful-looking round trip, an empty result, and nothing telling the
 * operator which of five requirements was the one that failed.
 *
 * When a publisher does start reading insights back, {@see REPORTING} gains its
 * type and every screen that asks is correct at once.
 */
final class InsightSources
{
    /**
     * Channel types that report post performance back to the engine.
     *
     * Empty, and honestly so. See the class docblock: nothing in
     * `App\Publishing` reads a metric back today.
     *
     * @var list<string>
     */
    public const array REPORTING = [];

    /**
     * The connected channels of this project that can report insights.
     *
     * Verified rather than merely configured, by the same reasoning
     * {@see ChannelController::ping()} applies: a row with
     * a URL in it is a claim, and a KPI unlocked by a claim is a number that
     * renders as zero for a month before anybody finds out.
     *
     * @return list<string>
     */
    public static function forCurrentProject(): array
    {
        // Unguarded, though {@see REPORTING} is empty today and this can only
        // return nothing. An `if (REPORTING === []) return []` short-circuit
        // reads as a saving and is not one: the query builder turns an empty
        // `whereIn` into `0 = 1`, which no database scans, and the branch would
        // be dead code that stops being dead on the day somebody adds a type —
        // exactly when nobody is looking at this file.
        /** @var list<string> $types */
        $types = Channel::query()
            ->whereIn('type', self::REPORTING)
            ->where('is_enabled', true)
            ->whereNotNull('verified_at')
            ->pluck('type')
            ->unique()
            ->values()
            ->all();

        return $types;
    }
}
