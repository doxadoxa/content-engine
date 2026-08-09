<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The content list — read-only, as §2.3 asks.
 *
 * Nothing generates units yet, so this exists to show that the model of §2 is
 * real: units grouped by locale, derivatives hanging off a parent, states a
 * pipeline will move. Phase 7 replaces it with the calendar and the approvals
 * queue.
 *
 * One row is a *unit*, not a row of the table. A bilingual guide is two rows in
 * `content_items` and both of them are roots, so listing rows directly would
 * print it twice — which is precisely the "юнит ≠ статья" mistake §2 is written
 * to prevent.
 */
class ContentItemController extends Controller
{
    public function __construct(private readonly CurrentProject $current) {}

    public function index(): Response
    {
        $groups = ContentItem::query()
            ->roots()
            ->select('locale_group_id')
            ->selectRaw('max(created_at) as latest_created_at')
            ->groupBy('locale_group_id')
            ->orderByDesc('latest_created_at')
            ->paginate(25)
            ->withQueryString();

        $groupIds = $groups->getCollection()
            ->pluck('locale_group_id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->values();

        // Only the groups on this page get their trees. Loading everything and
        // grouping in PHP made response size grow with the entire project.
        $roots = ContentItem::query()
            ->roots()
            ->whereIn('locale_group_id', $groupIds)
            ->withTree()
            ->with('contentPlan')
            ->get()
            ->groupBy('locale_group_id');

        $defaultLocale = $this->current->get()?->default_locale;

        $units = $groupIds->map(function (string $id) use ($roots, $defaultLocale): array {
            /** @var Collection<int, ContentItem> $group */
            $group = $roots->get($id, new Collection);

            return $this->toProps($group, $defaultLocale);
        })->all();

        $pagination = $groups->toArray();
        $pagination['data'] = $units;

        return Inertia::render('content/index', [
            'items' => $pagination,
        ]);
    }

    /**
     * @param  Collection<int, ContentItem>  $group
     * @return array<string, mixed>
     */
    private function toProps(Collection $group, ?string $defaultLocale): array
    {
        // The project's own language is the one an operator recognises the
        // unit by; anything else is a fallback for a unit not written in it.
        $item = $group->firstWhere('locale', $defaultLocale) ?? $group->first();

        /** @var ContentItem $item */
        return [
            'id' => $item->id,
            'title' => $item->title,
            'slug' => $item->slug,
            'locale' => $item->locale,
            'state' => $item->state->value,
            'state_label' => $item->state->label(),
            'is_live' => $item->state->isLive(),
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            'target_query' => $item->target_query,
            'topic_difficulty' => $item->topic_difficulty,
            'topic_volume' => $item->topic_volume,
            'published_at' => $item->published_at?->toIso8601String(),
            'plan_month' => $item->contentPlan?->month->format('Y-m'),
            'locales' => $group->pluck('locale')->unique()->sort()->values()->all(),
            // Across the whole unit: a Portuguese post derived from the
            // Portuguese row still belongs to this unit.
            'derivatives' => $group->sum(fn (ContentItem $row): int => $row->derivatives->count()),
        ];
    }
}
