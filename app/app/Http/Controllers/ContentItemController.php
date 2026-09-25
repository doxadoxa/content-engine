<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Publishing\Articles\ArticleSchedules;
use App\Support\Content\ManagerContent;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The content list — read-only, as §2.3 asks.
 *
 * Nothing generates units yet, so this exists to show that the model of §2 is
 * real: units grouped by locale, and states a pipeline will move. Phase 7
 * replaces it with the calendar and the approvals queue.
 *
 * One row is a *unit*, not a row of the table. A bilingual guide is two rows in
 * `content_items`, so listing rows directly would print it twice — which is precisely the "юнит ≠ статья" mistake §2 is written
 * to prevent.
 */
class ContentItemController extends Controller
{
    public function __construct(private readonly CurrentProject $current) {}

    public function index(Request $request): Response
    {
        $view = $request->query('view', 'all');
        abort_unless(is_string($view) && in_array($view, ['all', 'writing', 'review', 'scheduled', 'published'], true), 422);
        $search = $request->query('search', '');
        abort_unless(is_string($search), 422);
        $search = trim($search);
        abort_if(mb_strlen($search) > 120, 422);

        // Filter roots before grouping. A unit may have one title per locale,
        // and a search for a translated title must still find the unit once.
        $matching = $this->matching($view, $search);
        $groups = (clone $matching)
            ->select('locale_group_id')
            ->selectRaw('max(created_at) as latest_created_at')
            ->groupBy('locale_group_id')
            ->orderByDesc('latest_created_at')
            ->orderByDesc('locale_group_id')
            ->paginate(12)
            ->withQueryString();

        $groupIds = $groups->getCollection()
            ->pluck('locale_group_id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->values();

        // Keep the matching root separately from the complete unit. The
        // matching root makes a translated title and its filter state visible;
        // the complete unit keeps every locale on the card.
        $matchingRoots = (clone $matching)
            ->whereIn('locale_group_id', $groupIds)
            ->get()
            ->groupBy('locale_group_id');

        // Only the groups on this page get their trees. Loading everything and
        // grouping in PHP made response size grow with the entire project.
        $roots = ContentItem::query()
            ->whereIn('locale_group_id', $groupIds)
            ->withTree()
            ->with(['contentPlan', 'articleSchedule.delivery', 'project.channels'])
            ->get()
            ->groupBy('locale_group_id');

        $defaultLocale = $this->current->get()?->default_locale;

        $units = $groupIds->map(function (string $id) use ($roots, $matchingRoots, $defaultLocale): array {
            /** @var Collection<int, ContentItem> $group */
            $group = $roots->get($id, new Collection);
            /** @var Collection<int, ContentItem> $matches */
            $matches = $matchingRoots->get($id, new Collection);

            return $this->toProps($group, $matches, $defaultLocale);
        })->all();

        $pagination = $groups->toArray();
        $pagination['data'] = $units;

        return Inertia::render('content/index', [
            'items' => $pagination,
            'view' => $view,
            'search' => $search,
            'status_counts' => collect(['all', 'writing', 'review', 'scheduled', 'published'])
                ->mapWithKeys(fn (string $status): array => [$status => $this->countGroups($status, $search)])
                ->all(),
            'article_workflow' => $this->current->get() === null ? null : ManagerContent::workflow($this->current->get()),
            'planning' => PipelineRun::query()->inFlight()->whereIn('pipeline', ['research', 'planning'])->exists(),
        ]);
    }

    /** @return Builder<ContentItem> */
    private function matching(string $view, string $search): Builder
    {
        if ($search === '') {
            return ManagerContent::query($view);
        }

        // PostgreSQL's LIKE is case-sensitive. Escape wildcard characters so
        // a manager searching for a literal title does not turn it into a
        // pattern, while keeping SQLite test semantics aligned.
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

        return ManagerContent::query($view)
            ->whereRaw("title {$operator} ? ESCAPE '\\'", ['%'.$literal.'%']);
    }

    private function countGroups(string $view, string $search): int
    {
        return (clone $this->matching($view, $search))
            ->distinct()
            ->count('locale_group_id');
    }

    /**
     * @param  Collection<int, ContentItem>  $group
     * @param  Collection<int, ContentItem>  $matches
     * @return array<string, mixed>
     */
    private function toProps(Collection $group, Collection $matches, ?string $defaultLocale): array
    {
        // Prefer a root that actually matched the active filter or title
        // search. Otherwise an English row could replace a Portuguese title a
        // manager searched for, or hide the state that put this unit in view.
        $matchedIds = $matches->pluck('id');
        $matchingItems = $group->whereIn('id', $matchedIds);
        $item = $matchingItems->firstWhere('locale', $defaultLocale)
            ?? $matchingItems->first()
            ?? $group->firstWhere('locale', $defaultLocale)
            ?? $group->first();

        /** @var ContentItem $item */
        return [
            'id' => $item->id,
            'publication' => app(ArticleSchedules::class)->props($item),
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
        ];
    }
}
