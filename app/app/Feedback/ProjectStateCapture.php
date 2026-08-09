<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Contracts\AnalyticsGateway;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Integrations\Exceptions\GoogleUnavailable;
use App\Integrations\Threads\ThreadsInsights;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\ProjectState;
use App\Social\Governor;
use App\Support\Social\EntityCoverage;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * One day in the life of the whole project, written down (§6).
 *
 * §9 connected both halves of the data and reads them per unit. This is the
 * per-project sibling: the six signals of §6 gathered into the single row
 * `project_states` was built for, once a day, so that the trend exists at all.
 * Nothing downstream can recompute it — Search Console restates the recent past
 * as it settles, GA4's sampling is not reproducible on demand, and Threads
 * serves follower counts with no history whatsoever.
 *
 * **What is nullable and what is not.** Everything a vendor supplies is
 * nullable and is only written when that vendor answered; `posts_published` and
 * `replies_sent` come from our own tables and are always knowable. That split
 * is load-bearing rather than tidy: {@see ProjectState::replyRate()} returns
 * null for an unmeasured day and {@see Governor} skips those days rather than
 * counting them as bad ones, so a zero written by a broken connection would
 * throttle a healthy project down to the floor of §4.3 for a fortnight.
 *
 * **A gateway that did not answer never erases what an earlier run measured.**
 * The write is an `updateOrCreate` carrying only the columns that were read, so
 * re-capturing a day corrects the figures that came back and leaves the rest
 * alone. The alternative — building a complete attribute array with nulls for
 * whatever failed this minute — would let one transient 503 wipe a day that had
 * already been measured properly.
 *
 * **How far back, and why it is not yesterday alone.** The sweep re-captures a
 * trailing window ending on the previous complete day, because both Google
 * sources restate: {@see GoogleSearchConsole} reads settled data by design (its
 * docblock: fresh data "restates itself for days, and a refresh queue that acts
 * on a number which later moves is a queue that rewrites articles that were
 * fine") and GA4 keeps processing a day for up to two more. Today is never
 * captured at all — it is not over, and a partial day filed as a whole one is a
 * trough in the trend that never happened.
 */
final class ProjectStateCapture
{
    /**
     * How many complete days each sweep revisits, ending yesterday.
     *
     * Three, which is the shortest window that covers the restatement of both
     * sources: GA4 finishes processing a day within about 48 hours, and Search
     * Console's settled dataset lags by two to three. A sweep of one day would
     * capture yesterday once, before either vendor had finished counting it,
     * and never look again — which is how a permanent understatement gets into
     * a trend that is supposed to be the whole product.
     *
     * The cost of the window is three reads a day instead of one, against a
     * unique key that makes the second and third corrections rather than rows.
     */
    public const int TRAILING_DAYS = 3;

    /**
     * How old a day must be before Search Console is asked about it.
     *
     * Asked too early, the API answers *nothing* for the day rather than
     * answering provisionally — `dataState` is left at Google's default, which
     * is final data only. Nothing is indistinguishable from zero once it is in
     * an integer column, so a day younger than this is left null and picked up
     * by a later pass through the same window. This is the reason
     * {@see TRAILING_DAYS} is three rather than one: the window has to be long
     * enough to come back for the brand demand it could not read the first time.
     */
    public const int SEARCH_SETTLES_AFTER_DAYS = 3;

    public function __construct(
        private readonly SearchConsoleGateway $console,
        private readonly AnalyticsGateway $analytics,
        private readonly ThreadsInsights $insights,
        private readonly EntityCoverage $coverage,
        private readonly CurrentProject $current,
    ) {}

    /**
     * Capture the trailing window for one project, most recent day first.
     *
     * @return list<ProjectState>
     */
    public function sweep(Project $project, ?CarbonInterface $now = null): array
    {
        $today = CarbonImmutable::instance($now ?? Carbon::now())
            ->setTimezone($project->timezone)
            ->startOfDay();

        $rows = [];

        for ($back = 1; $back <= self::TRAILING_DAYS; $back++) {
            $rows[] = $this->capture($project, $today->subDays($back));
        }

        return $rows;
    }

    /**
     * One day, one row.
     *
     * Idempotent on `(project_id, captured_on)`, which is the only thing making
     * a re-run a correction instead of a duplicate — the sweep revisits three
     * days every night and a retried job is the normal case, not the exception.
     */
    public function capture(Project $project, CarbonInterface $day): ProjectState
    {
        return $this->current->run($project, function () use ($project, $day): ProjectState {
            // The calendar date, read in the project's timezone. Built from the
            // date rather than by shifting the instant: a caller that hands in
            // `2026-08-17 00:00 UTC` for a project in New York means the 17th,
            // and shifting the instant would file it under the 16th.
            $start = CarbonImmutable::parse($day->toDateString(), $project->timezone)->startOfDay();
            $end = $start->endOfDay();

            // Dates, because both vendors report days and neither takes an
            // instant. The pair is the same day on both ends.
            $from = Carbon::parse($start->toDateString());
            $to = $from->copy();

            $attributes = [
                // Ours, and therefore never null: §4.3's floor alerts on "we
                // published nothing this week", and it can only do that if a
                // week of zeroes is distinguishable from a week nobody measured.
                'posts_published' => ContentItem::query()
                    ->social()
                    ->whereBetween('published_at', [$start->utc(), $end->utc()])
                    ->count(),
                'replies_sent' => Interaction::query()
                    ->whereBetween('answered_at', [$start->utc(), $end->utc()])
                    ->count(),
                'entity_coverage' => $this->coverage->for($project, $start, $end),
            ];

            $settled = $start->diffInDays(CarbonImmutable::now()->setTimezone($project->timezone)->startOfDay())
                >= self::SEARCH_SETTLES_AFTER_DAYS;

            $brand = $settled ? $this->brandDemand($project, $from, $to) : null;

            if ($brand !== null) {
                $attributes['brand_impressions'] = $brand->impressions;
                $attributes['brand_clicks'] = $brand->clicks;
                $attributes['brand_queries'] = $brand->queries;
            }

            $audience = $this->audience($project, $from, $to);

            if ($audience !== null) {
                $attributes['total_sessions'] = $audience->totalSessions;
                $attributes['direct_sessions'] = $audience->directSessions;
                $attributes['referral_sessions'] = $audience->referralSessions;
                $attributes['social_sessions'] = $audience->socialSessions;
                $attributes['social_engaged_sessions'] = $audience->socialEngagedSessions;
                $attributes['social_engagement_seconds'] = $audience->socialEngagementSeconds;
                $attributes['conversions'] = $audience->conversions;
            }

            $health = $this->insights->forDay($project, $start);

            if ($health !== null) {
                // Followers only when the platform actually said a number. It
                // is served by a separate metric behind a separate permission,
                // and a project whose post metrics arrive without it should
                // keep whichever count was last measured rather than gain a
                // zero-follower day in the middle of its history.
                if ($health->followers !== null) {
                    $attributes['followers'] = $health->followers;
                }

                // The same rule, now for every metric rather than only for the
                // follower count: a number the platform did not send leaves the
                // column alone instead of writing a zero into it. A partial
                // answer is one of the shapes this endpoint really produces —
                // the permission is granted per metric — and a zero in
                // `post_replies` beside a real `post_impressions` is a measured
                // reply rate of nought, which is the one reading §4.3 acts on.
                foreach ([
                    'post_impressions' => $health->impressions,
                    'post_replies' => $health->replies,
                    'post_likes' => $health->likes,
                    'post_reposts' => $health->reposts,
                    'post_quotes' => $health->quotes,
                ] as $column => $value) {
                    if ($value !== null) {
                        $attributes[$column] = $value;
                    }
                }
            }

            // Provenance, so a run of nulls can be read as "the connection is
            // gone" rather than as "the audience left". §7 makes the engine
            // explain itself, and a snapshot that cannot say which of its
            // sources answered cannot be explained.
            //
            // Carried forward rather than overwritten, because the columns are:
            // a second pass that could not reach GA4 leaves yesterday's
            // sessions in place, and a flag saying they were never measured
            // would contradict the row it is attached to.
            $before = ProjectState::query()->where('captured_on', $start->toDateString())->first();
            $was = is_array($before?->raw['measured'] ?? null) ? $before->raw['measured'] : [];

            $attributes['raw'] = [
                'measured' => [
                    'search_console' => $brand !== null || ($was['search_console'] ?? false) === true,
                    'analytics' => $audience !== null || ($was['analytics'] ?? false) === true,
                    'threads' => $health !== null || ($was['threads'] ?? false) === true,
                ],
                'search_settled' => $settled,
                'captured_at' => Carbon::now()->toIso8601String(),
            ];

            return ProjectState::query()->updateOrCreate(
                ['captured_on' => $start->toDateString()],
                $attributes,
            );
        });
    }

    /**
     * Brand demand, or null when it could not be read.
     *
     * The transient case is caught rather than propagated. This runs across
     * every active project in one sweep, and one project's bad minute must not
     * cost the others their day — the window comes back tomorrow, which is what
     * makes swallowing it honest here and dishonest in a per-unit fetch that
     * only ever reads a day once.
     */
    private function brandDemand(Project $project, Carbon $from, Carbon $to): ?BrandDemand
    {
        if (! $this->console->isConfiguredFor($project)) {
            return null;
        }

        try {
            return $this->console->brandDemand($project, $from, $to);
        } catch (GoogleUnavailable $e) {
            Log::warning('Brand demand could not be captured', [
                'project' => $project->slug,
                'day' => $from->toDateString(),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function audience(Project $project, Carbon $from, Carbon $to): ?ProjectAudience
    {
        if (! $this->analytics->isConfiguredFor($project)) {
            return null;
        }

        try {
            return $this->analytics->audience($project, $from, $to);
        } catch (GoogleUnavailable $e) {
            Log::warning('The channel split could not be captured', [
                'project' => $project->slug,
                'day' => $from->toDateString(),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
