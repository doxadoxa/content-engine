<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenSocialPresenceIsOff;
use App\Enums\ChannelType;
use App\Enums\PipelineRunStatus;
use App\Enums\ProjectStatus;
use App\Models\Channel;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan social:plan <project>` — the weekly contour of §4.3.
 *
 * The same shape as {@see SocialListenCommand}, and for the same three reasons.
 *
 * **The project argument is optional**, because this runs on a schedule as well
 * as by hand and a scheduled command has nobody to name a project.
 *
 * **A project with no Threads channel is skipped silently.** A plan is a list of
 * times to publish at, and a project with nowhere to publish does not need one.
 * Starting the run anyway would write four step rows a week per project to
 * record that there was nothing to do. The check is on the channel rather than
 * on the token: §9 keeps the credential on `ProjectIntegration` and the channel
 * holds "the target and the toggles", and the question here is whether the
 * project intends to publish, not whether the connection is healthy this
 * minute — a broken token is the publisher's problem and an operator's, not a
 * reason to stop planning the week.
 *
 * **It refuses to stack two planning runs on one project**, scoped to
 * `social_plan` and nothing else. `withoutOverlapping` on the schedule stops
 * two *commands*; this stops the second project in one command's sweep from
 * starting a run on top of a live one. The scope is the rule
 * {@see EngineTickCommand::isBusy()} states — each contour blocks only itself —
 * and not an exception carved out for this command.
 */
class SocialPlanCommand extends Command
{
    use SkipsWhenSocialPresenceIsOff;

    protected $signature = 'social:plan
        {project? : Project slug or id. Omitted, every active project.}';

    protected $description = "Plan the week's social slots: the governor, the duty hours and what there is to say";

    public function handle(PipelineRunner $runner, CurrentProject $current): int
    {
        if ($this->socialPresenceIsOff()) {
            return self::SUCCESS;
        }

        $handle = $this->argument('project');

        if (! is_string($handle) || $handle === '') {
            foreach (Project::query()->where('status', ProjectStatus::Active)->get() as $project) {
                $this->plan($project, $runner, $current);
            }

            return self::SUCCESS;
        }

        $project = Project::query()->where('slug', $handle)->first()
            ?? Project::query()->whereKey($handle)->first();

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $this->plan($project, $runner, $current);

        return self::SUCCESS;
    }

    private function plan(Project $project, PipelineRunner $runner, CurrentProject $current): void
    {
        $current->run($project, function () use ($project, $runner): void {
            $hasChannel = Channel::query()
                ->where('type', ChannelType::Threads)
                ->exists();

            if (! $hasChannel) {
                // Silent, and not even a line: an operator reading the weekly
                // log wants the projects that planned.
                return;
            }

            if ($this->isPlanning()) {
                $this->components->warn("{$project->slug}: the previous planning run has not finished.");

                return;
            }

            try {
                $run = $runner->start('social_plan', $project);
            } catch (Throwable $e) {
                // One project's bad state must not stop the sweep for the rest,
                // exactly as in the tick and in listening.
                Log::warning('A planning run could not start', [
                    'project' => $project->slug,
                    'reason' => $e->getMessage(),
                ]);

                $this->components->error("{$project->slug}: {$e->getMessage()}");

                return;
            }

            $this->components->twoColumnDetail($project->slug, "planning (run {$run->getKey()})");
        });
    }

    /** Whether this project already has a planning run in flight. */
    private function isPlanning(): bool
    {
        return PipelineRun::query()
            ->where('pipeline', 'social_plan')
            ->whereIn('status', [PipelineRunStatus::Pending->value, PipelineRunStatus::Running->value])
            ->exists();
    }
}
