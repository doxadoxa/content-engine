<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Audit\SiteAuditStarter;
use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Console\Commands\EngineTickCommand;
use App\Enums\ContentItemState;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Events\PipelineRunFinished;
use App\Support\Engine\ArticleWorkflow;
use App\Support\Engine\MonthPlanner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

    /**
     * How many to draft when this launch is the card-free sample.
     *
     * One. The sample exists to answer "is the writing any good", and one
     * article answers that as well as three do — the other two are the same
     * question asked again at three times the cost, for somebody who has not
     * yet decided to pay us anything. The month's *plan* beside it is not
     * trimmed: a calendar of topics is one research run however many articles
     * come out of it, and it is the better half of the argument.
     */
    private const int PREVIEW_DRAFTS = 1;

    /**
     * The pipelines a launch is waiting for.
     *
     * Named rather than "anything in flight", which is what the two settling
     * checks below used to ask. The site audit is started by the launch and is
     * not part of it: a crawl of a hundred pages takes ten minutes of waiting on
     * somebody else's server, and counting it would keep the dashboard showing
     * "setting up" long after the articles it is really waiting for were
     * written. The operator would be looking at a spinner for a job whose
     * result appears on a different screen.
     *
     * The same shape and the same reasoning as {@see EngineTickCommand::CONTOUR}:
     * a list, because it is a rule about which work feeds which, and a derived
     * set would grow silently the day another pipeline is started from here.
     *
     * @var list<string>
     */
    private const array LAUNCH_PIPELINES = [
        'research',
        'planning',
        'generation',
    ];

    public function __construct(
        private readonly PipelineRunner $runner,
        private readonly CurrentProject $current,
        private readonly SiteAuditStarter $audits,
        private readonly Entitlements $entitlements,
    ) {}

    /** The first step: find out what this business could write about. */
    public function begin(Project $project): ?PipelineRun
    {
        $project->forceFill(['onboarding_status' => OnboardingStatus::Launching])->save();

        if (! ArticleWorkflow::enabled($project)) {
            $audit = $this->audits->start($project);
            $this->settle($project);

            return $audit;
        }

        if ($project->onboarded_at === null
            && ! isset($project->onboarding['article_automation_started_at'])) {
            $project->forceFill(['onboarding' => [...$project->onboarding, 'article_automation_started_at' => now()->toIso8601String()]])->save();
        }
        $research = app(MonthPlanner::class)->start($project);

        // The site the engine is about to write *for*, read once at the start.
        // Beside research rather than after it, and an independent contour
        // rather than a link in the chain: it needs nothing research produces,
        // nothing downstream needs its result, and a site whose sitemap is
        // unreachable must not stop a project from being planned. It is also
        // the moment the operator has just handed us a website, which is the
        // whole reason the audit knows one exists.
        $this->audits->start($project);

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

        if ($project === null) {
            return;
        }
        $continued = app(MonthPlanner::class)->afterResearch($run);
        if ($project->onboarding_status !== OnboardingStatus::Launching) {
            return;
        }

        if (! ArticleWorkflow::enabled($project)) {
            $this->settle($project);

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

        if ($run->pipeline === 'research' && isset($run->context['article_plan_month'])) {
            if ($continued === null) {
                $this->settleIfFinished($project);
            }

            return;
        }

        match ($run->pipeline) {
            'research' => $this->afterResearch($project),
            'planning' => $this->afterPlanning($project, $run),
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
            ->whereIn('pipeline', self::LAUNCH_PIPELINES)
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
        try {
            app(MonthPlanner::class)->start($project);
        } catch (ValidationException) {
            $this->settle($project);
        }
    }

    private function afterPlanning(Project $project, PipelineRun $run): void
    {
        if (! ArticleWorkflow::enabled($project)) {
            $this->settle($project);

            return;
        }

        $planId = $run->context['planning.plan_id'] ?? null;
        if (! is_string($planId) || $planId === '') {
            $this->settle($project);

            return;
        }
        $this->current->run($project, function () use ($project, $planId): void {
            $units = ContentItem::query()
                ->inState(ContentItemState::Idea)
                ->where('content_plan_id', $planId)
                ->where('locale', $project->default_locale)
                ->orderBy('scheduled_for')
                ->limit(min($this->firstDrafts($project), $this->entitlements->for($project)->remaining(Metric::Articles) ?? $this->firstDrafts($project)))
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
            ->whereIn('pipeline', self::LAUNCH_PIPELINES)
            ->whereIn('status', [PipelineRunStatus::Pending->value, PipelineRunStatus::Running->value])
            ->exists();

        if (! $stillRunning) {
            $this->settle($project);
        }
    }

    private function firstDrafts(Project $project): int
    {
        return $this->entitlements->for($project)->isPreview()
            ? self::PREVIEW_DRAFTS
            : self::FIRST_DRAFTS;
    }

    private function settle(Project $project): void
    {
        $project->forceFill([
            'onboarding_status' => OnboardingStatus::Active,
            'onboarded_at' => $project->onboarded_at ?? now(),
            // A finished preview is a finished preview, however it finished.
            //
            // Written here rather than counted from what was produced, because
            // every way a launch can end has to end the sample: a research run
            // that failed, a plan with nothing in it, an article that could not
            // be written. Any of those leaves a project whose launch is over,
            // and a preview that kept its allowance after that would simply be
            // an engine running for free for somebody with no card.
            //
            // {@see \App\Billing\Entitlement::refusal()} reads it.
            'onboarding' => $this->entitlements->for($project)->isPreview()
                ? [...$project->onboarding, 'preview_finished_at' => now()->toIso8601String()]
                : $project->onboarding,
        ])->save();

        $this->entitlements->forget($project);
    }
}
