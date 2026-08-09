<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenSocialPresenceIsOff;
use App\Enums\ProjectStatus;
use App\Feedback\ProjectStateCapture;
use App\Models\Project;
use App\Pipelines\Core\StepContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan project:capture <project>` — the daily snapshot of §6.
 *
 * The same shape as {@see SocialPlanCommand}: the project argument is optional
 * because this runs on a schedule and a scheduled command has nobody to name a
 * project, and one project's failure is logged rather than allowed to end the
 * sweep for the rest.
 *
 * **A plain command and not a pipeline, deliberately.** Every other scheduled
 * contour here starts a `PipelineRun`, and three properties of that machinery
 * are the reason this one does not:
 *
 * The metering it buys is empty. §8 meters the published post and the model
 * calls behind it, and {@see StepContext} exists so that "a
 * step cannot spend tokens without the run knowing". This capture spends no
 * tokens: it is four vendor reads and two `count()` queries, and a run row per
 * project per night carrying zero usage would add noise to the cost report
 * rather than a line in it.
 *
 * The run row would duplicate a record that already exists. A pipeline's trace
 * is its steps; this command's trace is the `project_states` row itself,
 * including `raw.measured`, which says which of the three sources answered. A
 * day that was captured has a row and a day that was not does not.
 *
 * And the contour locks work against it. `PipelineRunner` and the tick refuse
 * to start work while related work is in flight, which is right for a queue
 * that feeds itself and wrong for a measurement: yesterday's numbers have to be
 * written whatever else the project is doing, and they have to be written
 * before they stop being available.
 */
class ProjectCaptureCommand extends Command
{
    use SkipsWhenSocialPresenceIsOff;

    protected $signature = 'project:capture
        {project? : Project slug or id. Omitted, every active project.}
        {--on= : Capture this one day (Y-m-d) instead of the trailing window.}';

    protected $description = 'Write the daily project state: brand demand, channel split, Threads insights and entity coverage';

    public function handle(ProjectStateCapture $capture): int
    {
        if ($this->socialPresenceIsOff()) {
            return self::SUCCESS;
        }

        $handle = $this->argument('project');

        if (! is_string($handle) || $handle === '') {
            foreach (Project::query()->where('status', ProjectStatus::Active)->get() as $project) {
                $this->capture($project, $capture);
            }

            return self::SUCCESS;
        }

        $project = Project::query()->where('slug', $handle)->first()
            ?? Project::query()->whereKey($handle)->first();

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $this->capture($project, $capture);

        return self::SUCCESS;
    }

    /**
     * One project's window, or the single day `--on` names.
     *
     * `--on` exists for backfilling and for reading a day again by hand after
     * a connection was repaired. It bypasses no rule: the same
     * `updateOrCreate` runs, so naming a day that already has a row corrects
     * it, and naming today writes the incomplete day the sweep declines to.
     */
    private function capture(Project $project, ProjectStateCapture $capture): void
    {
        $on = $this->option('on');

        try {
            $rows = is_string($on) && $on !== ''
                ? [$capture->capture($project, Carbon::parse($on))]
                : $capture->sweep($project);
        } catch (Throwable $e) {
            // One project's bad state must not stop the sweep for the rest,
            // exactly as in the tick, in listening and in planning.
            Log::warning('A project state could not be captured', [
                'project' => $project->slug,
                'reason' => $e->getMessage(),
            ]);

            $this->components->error("{$project->slug}: {$e->getMessage()}");

            return;
        }

        $days = array_map(
            static fn (mixed $row): string => $row->captured_on->toDateString(),
            $rows,
        );

        $this->components->twoColumnDetail($project->slug, 'captured '.implode(', ', $days));
    }
}
