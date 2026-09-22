<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Models\AiAccuracyAssessment;
use App\Models\AiCorrectionRecheck;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\Accuracy\AccuracyAssessments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReconcileAiAccuracy extends Command
{
    protected $signature = 'visibility:reconcile-accuracy';

    protected $description = 'Recover queued factual assessments without repeating attempted model calls';

    public function handle(CurrentProject $current, AccuracyAssessments $assessments): int
    {
        foreach (Project::query()->where('status', ProjectStatus::Active)->cursor() as $project) {
            $current->run($project, function () use ($project, $assessments): void {
                AiAccuracyAssessment::query()->where('status', 'running')->where('started_at', '<', now()->subMinutes(31))
                    ->update(['status' => 'indeterminate', 'finished_at' => now()]);
                foreach (AiCorrectionRecheck::query()->cursor() as $recheck) {
                    try {
                        $assessments->recheckRun($recheck->sampling_run_id);
                    } catch (Throwable) {
                        Log::warning('A correction recheck could not yet be assessed.', ['sampling_run_id' => $recheck->sampling_run_id]);
                    }
                }
                foreach (AiAccuracyAssessment::query()->where('status', 'queued')->whereNull('pipeline_run_id')->get() as $assessment) {
                    $assessments->dispatch($project, $assessment);
                }
            });
        }

        return self::SUCCESS;
    }
}
