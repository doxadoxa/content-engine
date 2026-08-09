<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenSocialPresenceIsOff;
use App\Enums\PipelineRunStatus;
use App\Enums\ProjectStatus;
use App\Integrations\Threads\ThreadsConnection;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan social:listen <project>` — the hourly contour of §4.1.
 *
 * **Its own schedule entry, not a branch of `engine:tick`.** §11.3 says the
 * empty scheduler is closed inside this feature and §4.1 says listening is
 * hourly, and the two together rule out the tick:
 * {@see EngineTickCommand::isBusy()} refuses to start anything while any
 * pipeline is pending or running. That is the right rule for the work the tick
 * does — there is no point planning a month while research is still filling the
 * pool — and exactly the wrong one here, where a project generating a long
 * article would stop listening for as many hours as the article takes. §4.1's
 * whole claim is that this contour pays for itself on its own; a contour that
 * only runs when nothing else does is not one.
 *
 * **A project with neither a Threads connection nor a feed is skipped
 * silently.** That is three of the four projects this engine runs, and it is
 * the normal state rather than a fault. The pipeline itself skips cleanly for
 * such a project — every intake returns a skip and the run completes — but
 * starting it anyway would write seven step rows an hour, twenty-four hours a
 * day, per project, to record that there was nothing to do. The check is here
 * so the table stays about work that happened.
 */
class SocialListenCommand extends Command
{
    use SkipsWhenSocialPresenceIsOff;

    protected $signature = 'social:listen
        {project? : Project slug or id. Omitted, every active project.}';

    protected $description = 'Listen for signals: Threads keyword search, replies and the project RSS whitelist';

    public function handle(PipelineRunner $runner, CurrentProject $current, ThreadsConnection $threads): int
    {
        if ($this->socialPresenceIsOff()) {
            return self::SUCCESS;
        }

        // Optional, because this runs on a schedule as well as by hand and a
        // scheduled command has nobody to name a project.
        $handle = $this->argument('project');

        if (! is_string($handle) || $handle === '') {
            foreach (Project::query()->where('status', ProjectStatus::Active)->get() as $project) {
                $this->listen($project, $runner, $current, $threads);
            }

            return self::SUCCESS;
        }

        $project = Project::query()->where('slug', $handle)->first()
            ?? Project::query()->whereKey($handle)->first();

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $this->listen($project, $runner, $current, $threads);

        return self::SUCCESS;
    }

    private function listen(
        Project $project,
        PipelineRunner $runner,
        CurrentProject $current,
        ThreadsConnection $threads,
    ): void {
        $current->run($project, function () use ($project, $runner, $threads): void {
            if ($threads->for($project) === null && $project->feedUrls() === []) {
                // Silent, not an error, and not even a line: an operator
                // reading an hourly log wants to see the projects that listened.
                return;
            }

            if ($this->isListening($project)) {
                // A previous hour is still going. `withoutOverlapping` on the
                // schedule stops two *commands*, and this stops the second
                // command from stacking a second run on one project — which is
                // the shape that actually happens, because the scheduler runs
                // every project through one command.
                $this->components->warn("{$project->slug}: the previous listening run has not finished.");

                return;
            }

            try {
                $run = $runner->start('social_listen', $project);
            } catch (Throwable $e) {
                // One project's bad state must not stop the sweep for the rest,
                // exactly as in the tick.
                Log::warning('A listening run could not start', [
                    'project' => $project->slug,
                    'reason' => $e->getMessage(),
                ]);

                $this->components->error("{$project->slug}: {$e->getMessage()}");

                return;
            }

            $this->components->twoColumnDetail($project->slug, "listening (run {$run->getKey()})");
        });
    }

    /**
     * Whether this project already has a listening run in flight.
     *
     * Scoped to `social_listen` rather than to any pipeline, which is the whole
     * difference from {@see EngineTickCommand::isBusy()}: listening behind a
     * generation run is the stall §4.1 cannot afford, and listening behind
     * *listening* is the double-read it can.
     *
     * Scoped to the project explicitly, too. The tenant scope of
     * `BelongsToProject` is already on — the caller is inside
     * {@see CurrentProject::run()} — so the clause is redundant today and the
     * signature was not: a method taking a `Project` and never reading it says
     * the answer is about that project when nothing in it was. One sweep
     * command runs every project through here in turn, so "whichever project
     * the ambient scope happens to hold" is one refactor away from being the
     * wrong one.
     */
    private function isListening(Project $project): bool
    {
        return PipelineRun::query()
            ->where('project_id', $project->getKey())
            ->where('pipeline', 'social_listen')
            ->whereIn('status', [PipelineRunStatus::Pending->value, PipelineRunStatus::Running->value])
            ->exists();
    }
}
