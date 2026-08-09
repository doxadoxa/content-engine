<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The feedback screen (§9.6): indexation, impressions, decay, citability and
 * the refresh queue.
 *
 * The screen §7 deliberately left out, because until phase 9 there was nothing
 * true to put on it.
 */
class FeedbackController extends Controller
{
    public function __invoke(): Response
    {
        $live = ContentItem::query()
            ->roots()
            ->whereIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value])
            ->withSum('metrics as impressions', 'impressions')
            ->withSum('metrics as clicks', 'clicks')
            ->orderByDesc('impressions')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('feedback/index', [
            'units' => $live->through(fn (ContentItem $unit): array => [
                'id' => $unit->getKey(),
                'title' => $unit->title,
                'state' => $unit->state->value,
                'public_url' => $unit->public_url,
                'impressions' => (int) ($unit->getAttribute('impressions') ?? 0),
                'clicks' => (int) ($unit->getAttribute('clicks') ?? 0),
                // Indexed is "has ever had an impression". Zero is not proof of
                // the opposite, but it is the only signal search console gives.
                'indexed' => (int) ($unit->getAttribute('impressions') ?? 0) > 0,
                'cited' => array_keys(array_filter($unit->citations)),
                'citations_checked_at' => $unit->citations_checked_at?->toIso8601String(),
                'refresh_due' => $unit->refresh_due_at !== null,
                'refresh_reason' => $unit->refresh_reason,
            ]),
            'refresh_queue' => ContentItem::query()
                ->roots()
                ->whereIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value])
                ->whereNotNull('refresh_due_at')
                ->orderBy('refresh_due_at')
                ->orderBy('id')
                ->limit(20)
                ->get(['id', 'title', 'refresh_reason'])
                ->map(fn (ContentItem $unit): array => [
                    'id' => $unit->getKey(),
                    'title' => $unit->title,
                    'refresh_reason' => $unit->refresh_reason,
                ]),
            'summary' => $this->summary(),
            'trend' => $this->trend(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $live = ContentItem::query()
            ->roots()
            ->whereIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value]);

        return [
            'live' => $live->clone()->count(),
            'refreshing' => $live->clone()->whereNotNull('refresh_due_at')->count(),
            // A unit whose citations have been checked and found somewhere.
            'cited' => $live->clone()
                ->whereNotNull('citations_checked_at')
                ->withCitation()
                ->count(),
            'unchecked' => $live->clone()->whereNull('citations_checked_at')->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trend(): array
    {
        /** @var list<object{day: string, impressions: string, clicks: string}> $rows */
        $rows = ContentMetric::query()
            ->where('measured_on', '>=', now()->subDays(60)->toDateString())
            ->groupBy('measured_on')
            ->orderBy('measured_on')
            ->toBase()
            ->get([
                DB::raw('measured_on as day'),
                DB::raw('sum(impressions) as impressions'),
                DB::raw('sum(clicks) as clicks'),
            ])
            ->all();

        return array_map(static fn (object $row): array => [
            'day' => (string) $row->day,
            'impressions' => (int) $row->impressions,
            'clicks' => (int) $row->clicks,
        ], $rows);
    }
}
