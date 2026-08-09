<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Feedback;

use App\Enums\ContentItemState;
use App\Feedback\Contracts\AnalyticsGateway;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Illuminate\Support\Carbon;

/**
 * Pull engagement and match it to units (§9.1, extended).
 *
 * Runs beside {@see FetchPerformance} rather than after it: the two read
 * different accounts that are connected independently, and a project with
 * Search Console and no GA4 — or the other way round — must still get the half
 * it has.
 *
 * Writes onto the same daily row as the search metrics. One row per unit per
 * day is what makes a trend comparable; two tables would mean joining them
 * every time anybody asked a question about a day.
 */
class FetchEngagement extends AbstractStep
{
    public function __construct(private readonly AnalyticsGateway $analytics) {}

    public static function key(): string
    {
        return 'fetch_engagement';
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $this->analytics->isConfiguredFor($context->project)) {
            return StepResult::skip('This project has no Analytics property connected.');
        }

        $live = ContentItem::query()
            ->whereIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value])
            ->whereNotNull('public_url')
            ->get();

        if ($live->isEmpty()) {
            return StepResult::skip('Nothing is published yet, so there is nothing to measure.');
        }

        $byUrl = $live->keyBy('public_url');

        $days = max(1, (int) $context->get('days', 28));
        $to = Carbon::today();
        $from = $to->copy()->subDays($days);

        /** @var list<string> $urls */
        $urls = $byUrl->keys()->map(static fn (mixed $url): string => (string) $url)->values()->all();

        $rows = $this->analytics->engagement($context->project, $urls, $from, $to);

        // Summed per unit per day before writing. GA4 answers one row per path,
        // and `/post` and `/post?utm_source=x` are two paths for one article —
        // writing them one after another would leave the last one standing
        // rather than the total.
        $totals = [];

        foreach ($rows as $row) {
            $unit = $byUrl->get($row->url);

            if ($unit === null) {
                continue;
            }

            $key = $unit->getKey().'|'.$row->measuredOn->toDateString();

            $totals[$key] ??= [
                'unit' => $unit->getKey(),
                'date' => $row->measuredOn->toDateString(),
                'sessions' => 0,
                'engaged' => 0,
                'seconds' => 0,
            ];

            $totals[$key]['sessions'] += $row->sessions;
            $totals[$key]['engaged'] += $row->engagedSessions;
            $totals[$key]['seconds'] += $row->engagementSeconds;
        }

        foreach ($totals as $total) {
            // updateOrCreate against the same unique key the search metrics
            // use, so the two halves of a day land on one row whichever
            // arrives first.
            ContentMetric::query()->updateOrCreate(
                [
                    'content_item_id' => $total['unit'],
                    'measured_on' => $total['date'],
                ],
                [
                    'sessions' => $total['sessions'],
                    'engaged_sessions' => $total['engaged'],
                    'engagement_seconds' => $total['seconds'],
                ],
            );
        }

        /** @var list<string> $unitIds */
        $unitIds = $live->map(static fn (ContentItem $unit): string => $unit->getKey())->values()->all();

        return StepResult::success(new MetricsPayload($unitIds, count($totals)));
    }
}
