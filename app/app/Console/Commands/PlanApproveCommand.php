<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentPlan;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * `php artisan plan:approve <project> <month>` — §4.2's "аппрув в фазе 7
 * (сейчас — консольной командой)".
 *
 * Phase 7 replaces this with the approvals queue. Until then a plan is signed
 * off here, which keeps the rule that a plan is a proposal until a human says
 * otherwise rather than making draft a formality nobody can leave.
 */
class PlanApproveCommand extends Command
{
    protected $signature = 'plan:approve
        {project : Project slug or id}
        {month : Any date in the month, e.g. 2026-09}';

    protected $description = 'Approve a monthly content plan';

    public function handle(CurrentProject $current): int
    {
        $project = Project::query()->where('slug', $this->argument('project'))->first()
            ?? Project::query()->whereKey($this->argument('project'))->first();

        if ($project === null) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $month = Carbon::parse((string) $this->argument('month'))->startOfMonth();

        // Under the tenant, because every table below is scoped and a console
        // command has none — the same trap the pipeline runner hit.
        return $current->run($project, function () use ($month, $project): int {
            $plan = ContentPlan::query()->where('month', $month->toDateString())->first();

            if ($plan === null) {
                $this->components->error(
                    "No plan for {$project->name} in {$month->format('F Y')}. Run `pipeline:run planning` first."
                );

                return self::FAILURE;
            }

            try {
                $plan->approve();
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->info("Approved the {$month->format('F Y')} plan for {$project->name}.");
            $this->components->twoColumnDetail('Units', (string) $plan->contentItems()->count());

            return self::SUCCESS;
        });
    }
}
