<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\ContentItemState;
use App\Models\ContentGoal;
use App\Models\ContentItem;
use Illuminate\Support\Carbon;

/**
 * How often this project has actually been posting.
 *
 * The "currently" beside a proposed cadence. A target on its own is a number
 * with no scale — 7 posts a week is either a small increase or a standing start,
 * and which one it is decides whether an operator should approve the month. The
 * reference product puts the two side by side for exactly this reason, and it is
 * the one figure on that panel we can source honestly today: unlike a follower
 * count, it is arithmetic on rows this engine wrote itself.
 *
 * **Published, not approved.** {@see ActionBoard::progress()} counts
 * approved-or-published on purpose — signing off is the whole of the operator's
 * job and a delivery window is the engine's business. This is the opposite
 * question. It asks what the audience saw, because a cadence the audience never
 * experienced cannot explain a follower count that did not move.
 */
final class PublishedCadence
{
    /**
     * Posts published in the four weeks before a month began.
     *
     * The window ends where the month starts rather than at today, so the figure
     * does not change under an operator halfway through approving a plan, and so
     * that a month reviewed on the 20th is still compared against the run-up to
     * it rather than against itself.
     */
    public static function beforeMonth(Carbon $month): int
    {
        $start = $month->copy()->startOfMonth()->startOfDay();

        return ContentItem::query()
            ->where('state', ContentItemState::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $start->copy()->subWeeks(ContentGoal::WEEKS))
            ->where('published_at', '<', $start)
            ->count();
    }

    /**
     * That count as a weekly rate, to one decimal place.
     *
     * A decimal rather than a rounded integer because the interesting cases are
     * all below one. Three posts in four weeks is 0.8 a week, and rounding it to
     * 1 would put "Currently 1" beside a proposed 3 and understate the ask by
     * the whole of the difference.
     */
    public static function weeklyRate(int $published): float
    {
        return round($published / ContentGoal::WEEKS, 1);
    }
}
