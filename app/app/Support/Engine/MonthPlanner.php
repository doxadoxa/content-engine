<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Enums\ContentItemState;
use App\Enums\PipelineRunStatus;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Steps\Planning\PlanningWindow;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One requested calendar, including research when its idea pool is empty. */
final class MonthPlanner
{
    public function __construct(private readonly PipelineRunner $runner) {}

    public function start(Project $project, ?string $month = null): PipelineRun
    {
        return DB::transaction(function () use ($project, $month): PipelineRun {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            return app(CurrentProject::class)->run($locked, function () use ($locked, $month): PipelineRun {
                $window = PlanningWindow::forProject($locked, $month);
                $running = PipelineRun::query()->whereIn('pipeline', ['research', 'planning'])
                    ->whereIn('status', [PipelineRunStatus::Pending, PipelineRunStatus::Running])->oldest()->first();
                if ($running !== null) {
                    if ($running->pipeline === 'research' && ! isset($running->context['article_plan_month'])) {
                        $running->forceFill(['context' => [...$running->context, 'article_plan_month' => $window->month->toDateString(), 'article_plan_period' => $window->periodStart?->toIso8601String()]])->save();
                    }

                    return $running;
                }
                $this->assertMayPlan($locked, $window);
                if (! $this->hasIdeas()) {
                    $run = $this->runner->start('research', $locked);
                    $run->forceFill(['context' => [...$run->context, 'article_plan_month' => $window->month->toDateString(), 'article_plan_period' => $window->periodStart?->toIso8601String()]])->save();

                    return $run;
                }

                return $this->runner->start('planning', $locked, $window->input());
            });
        });
    }

    /** A repeated completion event cannot create another plan or paid run. */
    public function afterResearch(PipelineRun $research): ?PipelineRun
    {
        if ($research->pipeline !== 'research' || ! is_string($research->context['article_plan_month'] ?? null)) {
            return null;
        }

        return DB::transaction(function () use ($research): ?PipelineRun {
            $project = Project::query()->whereKey($research->project_id)->lockForUpdate()->firstOrFail();

            return app(CurrentProject::class)->run($project, function () use ($project, $research): ?PipelineRun {
                $fresh = PipelineRun::query()->whereKey($research->id)->lockForUpdate()->firstOrFail();
                if ($fresh->status !== PipelineRunStatus::Completed || isset($fresh->context['article_plan_result'])) {
                    return null;
                }
                try {
                    $month = (string) $fresh->context['article_plan_month'];
                    $window = PlanningWindow::forProject($project, $month, $fresh->context['article_plan_period'] ?? null);
                    $this->assertMayPlan($project, $window);
                    if (! $this->hasIdeas()) {
                        throw ValidationException::withMessages(['planning' => 'Research did not find a suitable new topic. Add a customer question to start an article.']);
                    }
                    $planned = PipelineRun::query()->where('pipeline', 'planning')
                        ->whereIn('status', [PipelineRunStatus::Pending, PipelineRunStatus::Running])->oldest()->first()
                        ?? $this->runner->start('planning', $project, $window->input());
                    $fresh->forceFill(['context' => [...$fresh->context, 'article_plan_result' => $planned->id]])->save();

                    return $planned;
                } catch (ValidationException $exception) {
                    $fresh->forceFill(['context' => [...$fresh->context, 'article_plan_result' => 'not_started', 'article_plan_error' => $exception->getMessage()]])->save();

                    return null;
                }
            });
        });
    }

    private function hasIdeas(): bool
    {
        return ContentItem::query()->inState(ContentItemState::Idea)->whereNull('content_plan_id')->exists();
    }

    private function assertMayPlan(Project $project, PlanningWindow $window): void
    {
        $entitlements = app(Entitlements::class);
        $entitlements->forget($project);
        $entitlement = $entitlements->for($project);
        if (ArticleWorkflow::calendarCapacity($project, $window) === 0) {
            throw ValidationException::withMessages(['planning' => 'This calendar already meets your publishing frequency. Existing articles are unchanged.']);
        }
        $existing = $window->periodStart === null
            ? ContentPlan::query()->where('month', $window->month->toDateString())->exists()
            : ArticleWorkflow::period($project, $window)?->plan_counted_at !== null;
        $refusal = $entitlement->refusal($existing ? Metric::Articles : Metric::ContentPlans);
        $message = $refusal === null ? 'This period already has enough articles prepared. Review the calendar before adding more.' : $refusal->message;
        if (! ArticleWorkflow::enabled($project) || $refusal !== null || ArticleWorkflow::capacity($project) === 0) {
            throw ValidationException::withMessages(['planning' => $message]);
        }
    }
}
