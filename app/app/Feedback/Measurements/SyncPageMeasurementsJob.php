<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Enums\ProjectStatus;
use App\Models\MeasurementRead;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

final class SyncPageMeasurementsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1900;

    public bool $failOnTimeout = true;

    public string $batchId;

    public function __construct(public string $projectId)
    {
        $this->batchId = (string) Str::ulid();
        $this->onQueue('pipeline-expensive');
    }

    public function uniqueId(): string
    {
        return $this->projectId;
    }

    public function handle(SynchronizePageMeasurements $sync): void
    {
        $project = Project::query()->find($this->projectId);
        if ($project?->status !== ProjectStatus::Active) {
            return;
        }
        $sync->sync($project, batchId: $this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        app(CurrentProject::class)->run($this->projectId, function (): void {
            MeasurementRead::query()->where('metadata->batch_id', $this->batchId)->where('status', ReadStatus::Reading)
                ->update(['status' => ReadStatus::Failed, 'reason' => 'The measurement did not finish. Run it again.', 'finished_at' => now()]);
        });
    }
}
