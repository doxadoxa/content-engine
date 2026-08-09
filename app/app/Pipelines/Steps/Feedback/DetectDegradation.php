<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Feedback;

use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decide what has decayed, and score what worked (§9.1, §9.2).
 *
 * Degradation is measured as a trend, not a level: two halves of the window
 * compared against each other. A single low reading is what every new article
 * looks like, and refreshing those would mean rewriting things that were never
 * given a chance.
 *
 * The cluster scores are the other half of §9.1 — "what worked, plan more of;
 * what did not, plan less". They are written onto the run's context so the
 * planner can read them without this step knowing anything about planning.
 */
class DetectDegradation extends AbstractStep
{
    /** Below this share of its own earlier performance, a unit has decayed. */
    private const float DECAY_RATIO = 0.6;

    /** Fewer impressions than this and the swing is noise, not a trend. */
    private const int NOISE_FLOOR = 30;

    /** Fewer sessions than this and an engagement ratio means nothing. */
    private const int SESSION_FLOOR = 20;

    /**
     * Page two: ranking, but below where anybody clicks.
     *
     * A unit sitting here is the cheapest win a project has. It has already
     * been written, already indexed, and already earns impressions — it is a
     * few improvements from page one, where the clicks are. Writing a new
     * article to chase the same query costs far more and starts from nothing.
     */
    private const float PAGE_TWO_FROM = 10.5;

    private const float PAGE_TWO_TO = 20.5;

    /** Below this the position is somebody's stray impression, not a ranking. */
    private const int PAGE_TWO_IMPRESSIONS = 50;

    public static function key(): string
    {
        return 'detect_degradation';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [FetchPerformance::key(), FetchEngagement::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        // Either half is enough to work from. A project with only Analytics
        // connected still gets its engagement collapse noticed, and one with
        // only Search Console still gets its decay noticed — waiting for both
        // would mean neither.
        $metrics = $this->unitsFrom($context);

        if ($metrics === null) {
            // Both fetches skipped: nothing published, or nothing connected. A
            // skip releases its dependants but hands them no payload — see
            // StepResult::skip().
            return StepResult::skip('There is no performance data to work from.');
        }

        $refreshing = [];
        $clusterClicks = [];
        $clusterUnits = [];

        foreach (ContentItem::query()->whereKey($metrics->unitIds)->get() as $unit) {
            $readings = $unit->metrics()->get();

            $cluster = $unit->cluster ?? 'unclustered';
            $clusterClicks[$cluster] = ($clusterClicks[$cluster] ?? 0) + (int) $readings->sum('clicks');
            $clusterUnits[$cluster] = ($clusterUnits[$cluster] ?? 0) + 1;

            // Search terms first, then what happened after the click. The
            // second catches what the first cannot see: a page whose
            // impressions are holding while the people who arrive stop
            // reading it has stopped answering its own question, and no
            // amount of search data says so.
            // Decay first, then engagement, then opportunity. The first two
            // are something going wrong; the third is something worth doing,
            // and a page that is both decaying and on page two should be
            // described by what broke.
            $reason = $this->decayReason($readings)
                ?? $this->disengagementReason($readings)
                ?? $this->nearlyRankingReason($readings);

            if ($reason === null) {
                continue;
            }

            $unit->forceFill([
                'refresh_due_at' => now(),
                'refresh_reason' => $reason,
            ])->save();

            $refreshing[$unit->getKey()] = $reason;
        }

        $scores = [];

        foreach ($clusterClicks as $cluster => $clicks) {
            $scores[$cluster] = round($clicks / max(1, $clusterUnits[$cluster]), 2);
        }

        arsort($scores);

        $context->remember('feedback.cluster_scores', $scores);

        return StepResult::success(new SignalsPayload($refreshing, $scores));
    }

    /** The day the window starts, for anything that wants to say so. */
    public static function windowStart(int $days): Carbon
    {
        return Carbon::today()->subDays($days);
    }

    /**
     * Ranking on page two, where nobody clicks.
     *
     * Not a fault: this article is working, it is simply below the fold of the
     * results. Queued as a refresh because improving something that already
     * earns impressions beats writing a new piece that earns none — which is
     * the trade the planner would otherwise make every month.
     *
     * @param  Collection<int, ContentMetric>  $readings
     */
    private function nearlyRankingReason(Collection $readings): ?string
    {
        $ranked = $readings->whereNotNull('position_tenths');

        if ($ranked->count() < 8) {
            // Too few readings to call a position. A week of data moves with
            // one good day.
            return null;
        }

        $impressions = (int) $ranked->sum('impressions');

        if ($impressions < self::PAGE_TWO_IMPRESSIONS) {
            return null;
        }

        // Weighted by impressions, not averaged flat: a day the page was barely
        // shown says less about where it ranks than a day it was shown often.
        $weighted = $ranked->sum(
            static fn (ContentMetric $reading): float => ($reading->position_tenths / 10) * $reading->impressions,
        );

        $position = $weighted / max(1, $impressions);

        if ($position < self::PAGE_TWO_FROM || $position > self::PAGE_TWO_TO) {
            return null;
        }

        return sprintf(
            'ranking at position %.1f on %d impressions — page two, a few improvements from clicks',
            $position,
            $impressions,
        );
    }

    /**
     * Traffic holding, engagement falling away.
     *
     * Ratio against itself over two halves of the window, like the impressions
     * check: an article that was always skimmed is not decaying, it was never
     * engaging, and rewriting it is a planning decision rather than a refresh.
     *
     * @param  Collection<int, ContentMetric>  $readings
     */
    private function disengagementReason(Collection $readings): ?string
    {
        $measured = $readings->whereNotNull('sessions');

        if ($measured->count() < 8) {
            // Either too new, or this project has no Analytics connected —
            // in which case `sessions` is null rather than zero, and null is
            // "we do not know" rather than "nobody came".
            return null;
        }

        $sorted = $measured->sortBy('measured_on')->values();
        $midpoint = (int) floor($sorted->count() / 2);

        $earlier = $sorted->slice(0, $midpoint);
        $later = $sorted->slice($midpoint);

        $before = (int) $earlier->sum('sessions');
        $after = (int) $later->sum('sessions');

        if ($before < self::SESSION_FLOOR || $after < self::SESSION_FLOOR) {
            // Too little traffic on either side for a rate to mean anything.
            return null;
        }

        $beforeRate = (int) $earlier->sum('engaged_sessions') / $before;
        $afterRate = (int) $later->sum('engaged_sessions') / $after;

        if ($beforeRate <= 0.0 || $afterRate >= $beforeRate * self::DECAY_RATIO) {
            return null;
        }

        return sprintf(
            'people stopped engaging: %d%% of visits held attention, now %d%%',
            (int) round($beforeRate * 100),
            (int) round($afterRate * 100),
        );
    }

    /**
     * The units either fetch found, or null when neither ran.
     *
     * Merged rather than preferred, because the two can legitimately disagree:
     * a unit GA4 saw and Search Console did not is still a unit with data.
     */
    private function unitsFrom(StepContext $context): ?MetricsPayload
    {
        $ids = [];
        $rows = 0;

        foreach ([FetchPerformance::key(), FetchEngagement::key()] as $step) {
            if (! $context->hasOutput($step)) {
                continue;
            }

            $payload = $context->output($step, MetricsPayload::class);

            $ids = [...$ids, ...$payload->unitIds];
            $rows += $payload->rows;
        }

        if ($ids === []) {
            return null;
        }

        return new MetricsPayload(array_values(array_unique($ids)), $rows);
    }

    /**
     * @param  Collection<int, ContentMetric>  $readings
     */
    private function decayReason(Collection $readings): ?string
    {
        if ($readings->count() < 8) {
            // Too new to have a trend. Refreshing on four days of data would
            // mean rewriting everything a week after it goes out.
            return null;
        }

        $sorted = $readings->sortBy('measured_on')->values();
        $midpoint = (int) floor($sorted->count() / 2);

        $earlier = $sorted->slice(0, $midpoint);
        $later = $sorted->slice($midpoint);

        $before = (int) $earlier->sum('impressions');
        $after = (int) $later->sum('impressions');

        if ($before < self::NOISE_FLOOR) {
            // It never had impressions to lose. That is a planning problem, not
            // a refresh one — rewriting an article nobody ever found does not
            // make anybody find it.
            return null;
        }

        if ($after >= $before * self::DECAY_RATIO) {
            return null;
        }

        return sprintf(
            'impressions fell from %d to %d over the window',
            $before,
            $after,
        );
    }
}
