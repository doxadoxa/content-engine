<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Onboarding\BriefOnboarding;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;

/**
 * `php artisan brief:onboard <project>` — the onboarding chat, in a terminal.
 *
 * The panel gets a nicer version of this; the questions and the compiling live
 * in {@see BriefOnboarding} so both ask the same things.
 */
class BriefOnboardCommand extends Command
{
    protected $signature = 'brief:onboard {project : Project slug or id}';

    protected $description = 'Interview an operator and compile a brand brief';

    public function handle(BriefOnboarding $onboarding, CurrentProject $current): int
    {
        $project = Project::query()->where('slug', $this->argument('project'))->first()
            ?? Project::query()->whereKey($this->argument('project'))->first();

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $this->components->info("Let's write a brand brief for {$project->name}.");

        $answers = [];

        foreach (BriefOnboarding::QUESTIONS as $question) {
            $answers[$question['key']] = (string) $this->ask($question['question']);
        }

        $brief = $current->run($project, fn () => $onboarding->compile($project, $answers));

        $this->newLine();
        $this->components->info("Saved as version {$brief->version}.");
        $this->line($brief->compileToPrompt());

        return self::SUCCESS;
    }
}
