<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Support\Content\ContentItemProps;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The content calendar (§7).
 *
 * A month at a time, cards by day. Units with no date are shown separately
 * rather than dropped: an idea nobody scheduled is exactly the thing an
 * operator is looking for when the month looks thin.
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $requested = $request->query('month');

        if ($requested !== null) {
            abort_unless(is_string($requested), 400, 'The month must be an ISO date.');

            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $requested);
            abort_unless(
                $parsed !== false && $parsed->format('Y-m-d') === $requested,
                400,
                'The month must be an ISO date.',
            );
        }

        $month = isset($parsed)
            ? Carbon::instance($parsed)->startOfMonth()
            : $this->monthWorthOpening();

        $units = ContentItem::query()
            ->roots()
            ->with(['localeVariants', 'derivatives'])
            ->whereBetween('scheduled_for', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->orderBy('scheduled_for')
            ->get();

        $plan = ContentPlan::query()->where('month', $month->toDateString())->first();

        return Inertia::render('calendar/index', [
            'month' => $month->toDateString(),
            'label' => $month->format('F Y'),
            'previous' => $month->copy()->subMonth()->toDateString(),
            'next' => $month->copy()->addMonth()->toDateString(),
            'days_in_month' => $month->daysInMonth,
            // The weekday the 1st falls on, so the grid can be laid out without
            // the client re-deriving a calendar from a date string.
            'starts_on' => (int) $month->dayOfWeekIso,
            'plan' => $plan === null ? null : [
                'id' => $plan->getKey(),
                'status' => $plan->status->value,
                'approved' => $plan->isApproved(),
            ],
            // One card per topic, not one per language. `roots()` only excludes
            // social derivatives — locale variants have no parent — so a project
            // publishing in three languages showed every article three times.
            // The card already carries a "3 langs" badge; it was being drawn on
            // each of the three.
            'units' => $this->oneCardPerTopic($units),
            'unscheduled' => ContentItem::query()
                ->roots()
                ->whereNull('scheduled_for')
                ->with(['localeVariants', 'derivatives'])
                ->orderByDesc('topic_volume')
                ->limit(25)
                ->get()
                ->map(fn (ContentItem $item): array => ContentItemProps::summary($item))
                ->all(),
        ]);
    }

    /**
     * Which month to open on when nobody asked for one.
     *
     * This month if it has anything, otherwise the next month that does. The
     * planner fills the month *ahead*, so on any day before the first of it the
     * default lands on an empty grid — which reads as an engine that produced
     * nothing, next to a Content list that plainly has four articles in it.
     */
    private function monthWorthOpening(): Carbon
    {
        $thisMonth = Carbon::now()->startOfMonth();

        $hasThisMonth = ContentItem::query()
            ->roots()
            ->whereBetween('scheduled_for', [
                $thisMonth->toDateString(),
                $thisMonth->copy()->endOfMonth()->toDateString(),
            ])
            ->exists();

        if ($hasThisMonth) {
            return $thisMonth;
        }

        $next = ContentItem::query()
            ->roots()
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '>=', $thisMonth->toDateString())
            ->orderBy('scheduled_for')
            ->value('scheduled_for');

        return $next === null
            ? $thisMonth
            : Carbon::parse((string) $next)->startOfMonth();
    }

    /**
     * The row that represents a topic, with its siblings' languages on it.
     *
     * The earliest row wins, which is the source: it is the one the keyword was
     * measured for, so its title and slug are in the language the topic was
     * planned in rather than in an adaptation's.
     *
     * @param  Collection<int, ContentItem>  $units
     * @return list<array<string, mixed>>
     */
    private function oneCardPerTopic(Collection $units): array
    {
        $grouped = $units
            ->groupBy(fn (ContentItem $item): string => (string) ($item->locale_group_id ?? $item->getKey()))
            ->map(function (Collection $group): array {
                /** @var ContentItem $lead */
                $lead = $group->sortBy('created_at')->first();

                return [
                    ...ContentItemProps::summary($lead),
                    'locales' => $group->pluck('locale')->unique()->sort()->values()->all(),
                ];
            })
            ->values();

        /** @var list<array<string, mixed>> $cards */
        $cards = $grouped->all();

        return $cards;
    }
}
