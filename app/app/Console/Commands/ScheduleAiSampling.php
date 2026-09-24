<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Visibility\Sampling\SamplingSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ScheduleAiSampling extends Command
{
    protected $signature = 'visibility:scheduled';

    protected $description = 'Dispatch the current monthly or weekly AI observation batch within each plan allowance';

    public function handle(SamplingSchedule $schedule): int
    {
        foreach (Project::query()->where('status', ProjectStatus::Active)->whereIn('id', ProjectSubscription::query()->select('project_id'))->cursor() as $project) {
            try {
                $schedule->dispatchDue($project);
            } catch (Throwable $error) {
                Log::notice('Scheduled AI checks could not start.', ['project_id' => $project->id, 'reason' => $error->getMessage()]);
            }
        }

        return self::SUCCESS;
    }
}
