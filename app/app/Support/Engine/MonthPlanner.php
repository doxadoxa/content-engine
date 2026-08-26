<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Audit\SiteAuditStarter;
use App\ContentStudio\ContentStudioOperations;
use App\Enums\PipelineRunStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\PlanningPipeline;
use Illuminate\Support\Facades\Cache;

/**
 * Asking for a month of articles, once.
 *
 * **The check and the start happen under one lock**, which is the whole reason
 * this is a class and not two lines in a controller. Planning reads the entire
 * keyword set and writes a month of units, so two of them racing plans the same
 * month twice — twice the model spend, and a calendar with every topic on it
 * twice.
 *
 * It was two lines in a controller, and then the same two lines again in the
 * assistant's `plan_month` tool, each doing an unguarded `exists()` before
 * starting. Two presses a moment apart — or a press and an assistant asked to
 * do it in the same breath — both saw nothing running. Disabling the button
 * does not close that: the tool is a second caller and a model presses faster
 * than a person.
 *
 * The same shape {@see SiteAuditStarter} and
 * {@see ContentStudioOperations} already use, for the same
 * reason.
 */
final class MonthPlanner
{
    public function __construct(private readonly PipelineRunner $runner) {}

    /**
     * Start one, or return null because one is already going.
     *
     * Null rather than an exception: "a month is already being planned" is a
     * normal answer to this question, and both callers have somewhere sensible
     * to put it.
     */
    public function start(Project $project): ?PipelineRun
    {
        return Cache::lock("planning:start:{$project->getKey()}", 10)
            ->block(3, function () use ($project): ?PipelineRun {
                $running = PipelineRun::query()
                    ->where('pipeline', PlanningPipeline::key())
                    ->whereIn('status', [PipelineRunStatus::Pending, PipelineRunStatus::Running])
                    ->exists();

                if ($running) {
                    return null;
                }

                return $this->runner->start(PlanningPipeline::key(), $project);
            });
    }
}
