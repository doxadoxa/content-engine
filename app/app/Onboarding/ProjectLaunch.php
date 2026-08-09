<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\ContentStudio\ContentStudioAction;
use App\ContentStudio\ContentStudioOperations;
use App\Enums\ContentItemState;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\ContentStudioPipeline;
use App\Pipelines\Events\PipelineRunFinished;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\Log;

/**
 * What happens after somebody finishes the wizard.
 *
 * Research, then planning, then a draft for the first few units — chained on
 * completion rather than dispatched together, because each one is the input to
 * the next and starting planning before research has found anything produces an
 * empty month.
 *
 * Driven by {@see PipelineRunFinished} rather than by a
 * job that waits: a run takes minutes and can be retried, resumed or fail, and
 * a worker sitting on `sleep()` through all that is a worker not doing
 * anything else.
 */
class ProjectLaunch
{
    /**
     * How many units to draft immediately. Enough that the dashboard has real
     * articles to show within the hour, not so many that a project nobody has
     * looked at yet has spent a month's budget.
     */
    private const int FIRST_DRAFTS = 3;

    public function __construct(
        private readonly PipelineRunner $runner,
        private readonly CurrentProject $current,
        private readonly ContentStudioOperations $studio,
    ) {}

    /** The first step: find out what this business could write about. */
    public function begin(Project $project): PipelineRun
    {
        $project->forceFill(['onboarding_status' => OnboardingStatus::Launching])->save();

        $research = $this->runner->start('research', $project);
        $this->startContentStudio($project);

        return $research;
    }

    /**
     * Called when any run of any project finishes.
     *
     * Launching only, and not merely "live": an active project's weekly
     * research run finishing is a routine event, and treating it as a link in
     * this chain would re-plan the month and draft three more articles every
     * single week. The chain exists to get a new project off the ground once.
     */
    public function advance(PipelineRun $run): void
    {
        $project = Project::query()->whereKey($run->project_id)->first();

        if ($project === null || $project->onboarding_status !== OnboardingStatus::Launching) {
            return;
        }

        // The Studio proposal starts beside research because the wizard has
        // already supplied enough project context. It is part of launch work,
        // but not a dependency of the SEO research/planning/generation chain:
        // one provider failure must not silently cancel the other product.
        if ($run->pipeline === ContentStudioPipeline::key()) {
            if (
                $run->status === PipelineRunStatus::Completed
                && ($run->input['action'] ?? null) === ContentStudioAction::Proposal->value
            ) {
                $this->startStudioDrafts($project, (string) ($run->input['content_plan_id'] ?? ''));

                return;
            }

            $this->settleIfFinished($project);

            return;
        }

        if (! in_array($run->pipeline, ['research', 'planning', 'generation'], true)) {
            return;
        }

        if ($run->status !== PipelineRunStatus::Completed) {
            // A failed run stops the chain. Carrying on would plan a month from
            // an idea pool that was never filled, and the operator would see a
            // thin calendar rather than the failure that caused it.
            Log::warning('A launch run failed; the chain stops here', [
                'project' => $project->slug,
                'pipeline' => $run->pipeline,
                'step' => $run->failed_step_key,
            ]);

            $this->settle($project);

            return;
        }

        match ($run->pipeline) {
            'research' => $this->afterResearch($project),
            'planning' => $this->afterPlanning($project),
            'generation' => $this->afterGeneration($project),
        };
    }

    /**
     * Settle a launch that has nothing left running.
     *
     * The chain is driven by an event, and an event that never arrives — a
     * worker killed mid-run, a queue drained by hand, a release deployed
     * between one step and the next — leaves the project saying "setting up"
     * with a spinner and no way out. Checked whenever the dashboard is opened,
     * because that is exactly when somebody is looking at the spinner and
     * wondering.
     *
     * Deliberately not a timeout: "no runs are pending or running" is a fact,
     * where "it has been an hour" is a guess that gets a busy queue wrong.
     *
     * With one bound on the fact, added after the fact turned out to be
     * survivable by a run that had stopped existing in every sense except its
     * status column ({@see PipelineRun::scopeInFlight()}). A launch is exactly
     * where that is least forgivable — the operator has never seen the product
     * work, and the spinner is the whole of their first hour. `abandon_after`
     * is hours, so a busy queue is still read as busy; what it excludes is
     * wreckage.
     */
    public function settleIfFinished(Project $project): void
    {
        if ($project->onboarding_status !== OnboardingStatus::Launching) {
            return;
        }

        $live = PipelineRun::acrossProjects()
            ->where('project_id', $project->getKey())
            ->inFlight()
            ->exists();

        if ($live) {
            return;
        }

        Log::info('A launch had nothing left running and was settled', [
            'project' => $project->slug,
        ]);

        $this->settle($project);
    }

    private function afterResearch(Project $project): void
    {
        $this->current->run($project, function () use ($project): void {
            $this->runner->start('planning', $project);
        });
    }

    private function startContentStudio(Project $project): void
    {
        $this->current->run($project, function () use ($project): void {
            $plan = ContentPlan::query()->firstOrCreate([
                'month' => now()->startOfMonth(),
            ]);

            if ($plan->assistant_version > 0) {
                return;
            }

            $this->studio->start($project, $plan, ContentStudioAction::Proposal);
        });
    }

    private function startStudioDrafts(Project $project, string $planId): void
    {
        $this->current->run($project, function () use ($project, $planId): void {
            $plan = ContentPlan::query()->whereKey($planId)->first();

            if ($plan === null) {
                $this->settleIfFinished($project);

                return;
            }

            $hasStarterDrafts = ContentItem::query()
                ->where('content_plan_id', $plan->getKey())
                ->whereNotNull('content_idea_id')
                ->exists();

            if ($hasStarterDrafts) {
                $this->settleIfFinished($project);

                return;
            }

            $this->studio->start($project, $plan, ContentStudioAction::Generate, [
                'initial' => true,
            ]);
        });
    }

    private function afterPlanning(Project $project): void
    {
        $this->current->run($project, function () use ($project): void {
            $units = ContentItem::query()
                ->roots()
                ->inState(ContentItemState::Idea)
                ->whereNotNull('content_plan_id')
                ->where('locale', $project->default_locale)
                ->orderBy('scheduled_for')
                ->limit(self::FIRST_DRAFTS)
                ->get();

            if ($units->isEmpty()) {
                $this->settle($project);

                return;
            }

            foreach ($units as $unit) {
                $this->runner->start('generation', $project, [], $unit->getKey());
            }
        });
    }

    /**
     * The last generation to finish settles the project.
     *
     * Counted rather than remembered: the drafts run in parallel and any one of
     * them could be the last, so "is anything still running" is a question for
     * the database rather than a counter somebody has to keep correct.
     */
    private function afterGeneration(Project $project): void
    {
        $stillRunning = PipelineRun::acrossProjects()
            ->where('project_id', $project->getKey())
            ->whereIn('status', [PipelineRunStatus::Pending->value, PipelineRunStatus::Running->value])
            ->exists();

        if (! $stillRunning) {
            $this->settle($project);
        }
    }

    private function settle(Project $project): void
    {
        $project->forceFill([
            'onboarding_status' => OnboardingStatus::Active,
            'onboarded_at' => $project->onboarded_at ?? now(),
        ])->save();
    }
}
