<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\FactMaintenance\FactMaintenance;
use App\FactMaintenance\FactUsageImpacts;
use App\Models\FactMaintenanceCheck;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;

final class ReconcileFactMaintenance extends Command
{
    protected $signature = 'facts:reconcile-maintenance';

    protected $description = 'Recover unstarted page fact checks without repeating potentially paid attempts';

    public function handle(CurrentProject $current, FactMaintenance $maintenance): int
    {
        foreach (Project::query()->cursor() as $project) {
            $current->run($project, function () use ($project, $maintenance): void {
                if ($project->status === ProjectStatus::Active) {
                    FactMaintenanceCheck::query()->where('status', 'running')->where('attempted_at', '<', now()->subMinutes(31))->update(['status' => 'indeterminate',
                        'reason' => 'The earlier attempt did not record its outcome. A fresh explicit check is required; possible paid work is not repeated automatically.', 'finished_at' => now()]);
                    $maintenance->dispatch($project);
                }
                app(FactUsageImpacts::class)->reconcile();
            });
        }

        return self::SUCCESS;
    }
}
