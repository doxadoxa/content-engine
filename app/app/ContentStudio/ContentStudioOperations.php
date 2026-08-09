<?php

declare(strict_types=1);

namespace App\ContentStudio;

use App\Enums\PipelineRunStatus;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\ContentStudioPipeline;
use Illuminate\Support\Facades\Cache;

/** The one durable dispatch boundary shared by Studio and project onboarding. */
final class ContentStudioOperations
{
    public function __construct(private readonly PipelineRunner $pipelines) {}

    /** @param array<string, mixed> $input */
    public function start(
        Project $project,
        ContentPlan $plan,
        ContentStudioAction $action,
        array $input = [],
    ): PipelineRun {
        return Cache::lock("content-studio:dispatch:{$plan->getKey()}", 10)
            ->block(3, function () use ($project, $plan, $action, $input): PipelineRun {
                $active = PipelineRun::query()
                    ->where('pipeline', ContentStudioPipeline::key())
                    ->whereIn('status', [PipelineRunStatus::Pending, PipelineRunStatus::Running])
                    ->where('input->content_plan_id', $plan->getKey())
                    ->latest()
                    ->first();

                if ($active !== null) {
                    return $active;
                }

                return $this->pipelines->start(
                    ContentStudioPipeline::key(),
                    $project,
                    [
                        'action' => $action->value,
                        'content_plan_id' => $plan->getKey(),
                        ...$input,
                    ],
                );
            });
    }
}
