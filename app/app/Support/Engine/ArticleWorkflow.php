<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Enums\ContentItemState;
use App\Enums\PipelineRunStatus;
use App\Models\ArticlePlanningPeriod;
use App\Models\ArticleSchedule;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Steps\Planning\PlanningWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ArticleWorkflow
{
    /** Product capability, independent of the subscription version. */
    public static function enabled(Project $project): bool
    {
        $plan = app(Entitlements::class)->for($project)->plan;

        return $plan !== null && ($plan->limit('articles') === null || $plan->limit('articles') > 0);
    }

    public static function calendarCapacity(Project $project, PlanningWindow $window): int
    {
        if ($window->periodStart !== null) {
            $period = self::period($project, $window);
            $planned = $period === null ? 0 : ContentItem::acrossProjects()->where('project_id', $project->id)
                ->where('article_planning_period_id', $period->id)->count();

            return min(max(0, ($window->pacingLimit ?? 0) - $planned), count(self::openSlots($project, $window)));
        }
        $planned = ContentItem::acrossProjects()->where('project_id', $project->id)
            ->whereNotNull('content_plan_id')->whereBetween('scheduled_for', [$window->start->toDateString(), $window->end->toDateString()])
            ->distinct()->count('locale_group_id');

        return max(0, $window->capacityFor(min(7, $project->weeklyTarget())) - $planned);
    }

    public static function usesBillingPeriod(Project $project): bool
    {
        return app(Entitlements::class)->for($project)->subscription !== null;
    }

    public static function period(Project $project, PlanningWindow $window): ?ArticlePlanningPeriod
    {
        return $window->periodStart === null ? null : ArticlePlanningPeriod::acrossProjects()->where('project_id', $project->id)
            ->where('period_started_at', $window->periodStart->copy()->utc())->first();
    }

    /** @return list<Carbon> */
    public static function openSlots(Project $project, PlanningWindow $window): array
    {
        // Include manual and previous-period work: a new plan must not stack
        // its automatic slots on an existing publication at the same instant.
        $planned = ContentItem::acrossProjects()->where('project_id', $project->id)->whereDoesntHave('articleSchedule')
            ->whereBetween('planned_publication_at', [$window->start->copy()->utc(), $window->end->copy()->utc()])
            ->pluck('planned_publication_at');
        $scheduled = ArticleSchedule::acrossProjects()->where('project_id', $project->id)
            ->whereBetween('publish_at', [$window->start->copy()->utc(), $window->end->copy()->utc()])
            ->whereNotIn('status', ['cancelled'])->pluck('publish_at');
        $occupied = [];
        $daily = [];
        foreach ($planned->concat($scheduled) as $value) {
            $at = Carbon::parse($value)->setTimezone($window->start->getTimezone());
            $occupied[$at->getTimestamp()] = true;
            $daily[$at->toDateString()] = ($daily[$at->toDateString()] ?? 0) + 1;
        }
        $open = [];
        foreach ($window->publicationSlots() as $at) {
            $day = $at->toDateString();
            if (! isset($occupied[$at->getTimestamp()]) && ($daily[$day] ?? 0) < 2) {
                $open[] = $at;
                $daily[$day] = ($daily[$day] ?? 0) + 1;
            }
        }

        return $open;
    }

    /**
     * Where a single article asked for by hand should go.
     *
     * Not "tomorrow at nine", which is what asking twice used to mean: both
     * articles landed on the same instant, and `publish:approved` sent them
     * out together — the burst the pacing exists to prevent. The planner
     * already answers this question with {@see openSlots()}, so it is asked
     * rather than answered a second time here.
     *
     * Null when the period is full. A caller must refuse rather than stack
     * another article onto a slot that is already taken.
     */
    public static function nextOpenSlot(Project $project): ?Carbon
    {
        try {
            $window = PlanningWindow::forProject($project);
        } catch (ValidationException) {
            // No confirmed billing period to pace across. Older plans have no
            // slots of their own, so they keep the fixed morning they had.
            return Carbon::tomorrow($project->timezone)->setTime(9, 0);
        }

        if ($window->periodStart === null) {
            return Carbon::tomorrow($project->timezone)->setTime(9, 0);
        }

        return self::openSlots($project, $window)[0] ?? null;
    }

    /** Remaining first approvals, less work already being prepared for them. */
    public static function capacity(Project $project): ?int
    {
        $entitlements = app(Entitlements::class);
        $entitlements->forget($project);
        $entitlement = $entitlements->for($project);
        if (! self::enabled($project) || ! $entitlement->mayGenerate()) {
            return 0;
        }
        $remaining = $entitlement->remaining(Metric::Articles);
        if ($remaining === null) {
            return null;
        }
        $prepared = ContentItem::acrossProjects()->where('project_id', $project->id)
            ->whereNotIn('id', DB::table('article_approval_records')->select('content_item_id')->where('project_id', $project->id))
            ->whereIn('state', [ContentItemState::Idea, ContentItemState::Queued, ContentItemState::Generating, ContentItemState::Draft])
            ->where(function ($query) use ($project): void {
                $query->whereNotNull('content_plan_id')->orWhere('state', '!=', ContentItemState::Idea->value)
                    ->orWhereIn('id', PipelineRun::acrossProjects()->select('content_item_id')->where('project_id', $project->id)
                        ->where('pipeline', 'generation')->whereIn('status', [PipelineRunStatus::Pending, PipelineRunStatus::Running])->whereNotNull('content_item_id'));
            })->count();

        return max(0, $remaining - $prepared);
    }
}
