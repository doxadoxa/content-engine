<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Onboarding\ProjectLaunch;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\SiteAuditFixPlanPipeline;
use App\Pipelines\Definitions\SiteAuditPipeline;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a sweep is started.
 *
 * Three callers want to start one and all three want the same two guards: the
 * project must have a website, and there must not already be a sweep in flight.
 * {@see ProjectLaunch} starts one when a project goes live, `audit:sweep`
 * starts one when the last is stale, and the recheck button starts one because
 * somebody asked — and a button pressed twice, a scheduler that overlaps a
 * launch, or a launch of a project the operator immediately rechecks are all
 * ordinary and must not produce two crawls of one site at once.
 *
 * "In flight" is asked of the *run* rather than of the audit row, deliberately.
 * The row is closed by the sweep's own last step, so a run that dies leaves it
 * open forever and a guard reading it would refuse every sweep from then on.
 * {@see PipelineRun::scopeInFlight()} carries the bound that makes wreckage
 * stop counting, which is exactly the property needed here.
 */
class SiteAuditStarter
{
    public function __construct(
        private readonly PipelineRunner $runner,
        private readonly CurrentProject $current,
    ) {}

    /**
     * Start a sweep unless one is already running.
     *
     * @param  array<string, mixed>  $input
     * @return PipelineRun|null null when it was refused, with no side effect
     */
    public function start(Project $project, array $input = []): ?PipelineRun
    {
        if (! $this->canAudit($project)) {
            return null;
        }

        // The check and the start happen together, under a lock on the project
        // row. Without it the two are separate statements with a gap between
        // them, and every caller can arrive in that gap: a double-clicked
        // Recheck, the scheduler waking while a launch is still starting, an
        // operator rechecking a project that has just launched. Both observe no
        // run in flight, both start one, and a customer's server is crawled
        // twice at once by an engine that promised it would not be.
        //
        // The same shape as OnboardingController::launch(), which locks the
        // project row to make a second final click a no-op rather than a second
        // set of channels. The throttle in front of the route bounds how often
        // requests arrive; it does nothing about two that arrive together.
        return DB::transaction(function () use ($project, $input): ?PipelineRun {
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->first();

            if ($locked === null || $this->isRunning($locked)) {
                return null;
            }

            return $this->current->run($locked, fn (): PipelineRun => $this->runner->start(
                SiteAuditPipeline::key(),
                $locked,
                $input,
            ));
        });
    }

    /**
     * Start a sweep only if the last one is older than the refresh window.
     *
     * The window is measured from the newest audit of any kind, finished or
     * not, so a sweep that failed halfway still holds the next one off for a
     * week. A site whose audit cannot complete would otherwise be crawled every
     * night by a scheduler retrying it — the most expensive possible response
     * to a site that is already unwell.
     */
    public function startIfDue(Project $project): ?PipelineRun
    {
        if (! $this->canAudit($project)) {
            return null;
        }

        $due = $this->current->run($project, function (): bool {
            $newest = SiteAudit::query()->max('created_at');

            if ($newest === null) {
                return true;
            }

            $days = max(1, (int) config('audit.refresh_after_days', 7));

            return Carbon::parse((string) $newest)->addDays($days)->isPast();
        });

        return $due ? $this->start($project) : null;
    }

    /**
     * Ask the model to order one sweep's findings.
     *
     * Refused for a sweep that has not finished — the findings are still being
     * written, and a plan drawn from half of them would be wrong in the way
     * that is hardest to notice.
     */
    public function startFixPlan(Project $project, SiteAudit $audit): ?PipelineRun
    {
        if (! $audit->isFinished()) {
            return null;
        }

        // Locked for the same reason as start(), and with more at stake per
        // duplicate: this one spends tokens. Two presses in the same instant
        // would buy two plans for one sweep.
        return DB::transaction(function () use ($project, $audit): ?PipelineRun {
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->first();

            if ($locked === null || $this->isWritingFixPlan($locked, $audit)) {
                return null;
            }

            return $this->current->run($locked, fn (): PipelineRun => $this->runner->start(
                SiteAuditFixPlanPipeline::key(),
                $locked,
                ['site_audit_id' => (string) $audit->getKey()],
            ));
        });
    }

    /** Whether a sweep is running for this project right now. */
    public function isRunning(Project $project): bool
    {
        return PipelineRun::acrossProjects()
            ->where('project_id', $project->getKey())
            ->where('pipeline', SiteAuditPipeline::key())
            ->inFlight()
            ->exists();
    }

    public function isWritingFixPlan(Project $project, SiteAudit $audit): bool
    {
        return PipelineRun::acrossProjects()
            ->where('project_id', $project->getKey())
            ->where('pipeline', SiteAuditFixPlanPipeline::key())
            ->where('input->site_audit_id', (string) $audit->getKey())
            ->inFlight()
            ->exists();
    }

    /**
     * A project with no website has nothing to audit.
     *
     * True of a draft that never finished the wizard's first step, which is the
     * only way it happens — and refusing here rather than in the pipeline means
     * the caller gets a null instead of a failed run on the operator's screen.
     */
    private function canAudit(Project $project): bool
    {
        return trim((string) $project->website_url) !== '';
    }
}
