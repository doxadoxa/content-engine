<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Feedback;

use App\Enums\ContentItemState;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Illuminate\Support\Carbon;

/**
 * Pull search performance and match it to units (§9.1).
 *
 * Matched on `public_url` — the value the receiver answered with back in phase
 * 6. Nothing else joins the two halves of this system, which is why a receiver
 * that does not return one is called under-connected rather than fine.
 */
class FetchPerformance extends AbstractStep
{
    public function __construct(private readonly SearchConsoleGateway $console) {}

    public static function key(): string
    {
        return 'fetch_performance';
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $this->console->isConfiguredFor($context->project)) {
            // A skip, not a failure: a project whose owner has not connected
            // Google is not a broken project, and a scheduled run that fails
            // every night for it teaches the operator to ignore failures.
            return StepResult::skip('This project has no Search Console connected.');
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

        $rows = $this->console->performance(
            project: $context->project,
            urls: $urls,
            from: $from,
            to: $to,
        );

        foreach ($rows as $row) {
            $unit = $byUrl->get($row->url);

            if ($unit === null) {
                continue;
            }

            // updateOrCreate: the API restates recent days as they settle, and
            // a second fetch of the same day must correct the row rather than
            // collide with the unique index.
            ContentMetric::query()->updateOrCreate(
                [
                    'content_item_id' => $unit->getKey(),
                    'measured_on' => $row->measuredOn->toDateString(),
                ],
                [
                    'impressions' => $row->impressions,
                    'clicks' => $row->clicks,
                    'position_tenths' => $row->position === null ? null : (int) round($row->position * 10),
                    // Impressions are proof of indexing; zero is not proof of
                    // the opposite, but it is the only signal available here.
                    'indexed' => $row->impressions > 0,
                ],
            );
        }

        /** @var list<string> $unitIds */
        $unitIds = $live->map(static fn (ContentItem $unit): string => $unit->getKey())->values()->all();

        return StepResult::success(new MetricsPayload($unitIds, count($rows)));
    }
}
