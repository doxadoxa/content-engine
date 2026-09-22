<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\Sampling\SamplingRuns;
use App\Visibility\Sampling\SamplingTiming;
use Illuminate\Console\Command;

/** Recover committed dispatches; never repeat a cell after an API request may have started. */
final class ReconcileAiSampling extends Command
{
    protected $signature = 'visibility:reconcile';

    protected $description = 'Recover undispatched AI sampling cells and mark unresolved attempts honestly';

    public function handle(CurrentProject $current, SamplingRuns $runs): int
    {
        foreach (Project::query()->where('status', ProjectStatus::Active)->cursor() as $project) {
            $current->run($project, function () use ($project, $runs): void {
                AiSamplingCell::query()->where('status', 'running')->where('attempted_at', '<', SamplingTiming::staleBefore())
                    ->update(['status' => 'indeterminate', 'reason' => 'The request has no recorded outcome and may have incurred cost. An explicit recheck is required.', 'finished_at' => now()]);
                foreach (AiSamplingRun::query()->whereIn('status', ['queued', 'running'])->get() as $run) {
                    $runs->dispatch($project, $run);
                }
            });
        }

        return self::SUCCESS;
    }
}
