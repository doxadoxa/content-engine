<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PipelineStepStatus;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStep>
 */
class PipelineStepFactory extends Factory
{
    protected $model = PipelineStep::class;

    /** Rows this instance has handed out, per run — see {@see BrandBriefFactory}. */
    private int $issued = 0;

    /**
     * `pipeline_run_id` resolves first so the step lands in its run's project
     * rather than resolving the tenant a second time.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_run_id' => PipelineRun::factory(),
            'project_id' => fn (array $attributes): string => PipelineRun::acrossProjects()
                ->whereKey($attributes['pipeline_run_id'])
                ->firstOrFail()
                ->project_id,
            // Unique per run, because (pipeline_run_id, step_key) is unique and
            // a fixed key would collide on the second row of a batch.
            'step_key' => fn (): string => 'step_'.(++$this->issued),
            'position' => fn (): int => $this->issued,
            'status' => PipelineStepStatus::Succeeded,
            'attempt' => 1,
            'provider' => 'fake',
            'model' => 'fake-model',
            'role' => 'draft',
            'input_tokens' => fake()->numberBetween(200, 5_000),
            'output_tokens' => fake()->numberBetween(100, 2_000),
            'cost_micros' => fake()->numberBetween(500, 90_000),
            'latency_ms' => fake()->numberBetween(20, 40_000),
            'output' => [],
            'error' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => PipelineStepStatus::Pending,
            'attempt' => 0,
            'provider' => null,
            'model' => null,
            'role' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_micros' => 0,
            'latency_ms' => null,
            'output' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    /** Claimed and still working — the shape a killed worker leaves behind. */
    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => PipelineStepStatus::Running,
            'output' => null,
            'finished_at' => null,
        ]);
    }

    public function failed(string $message = 'the provider refused'): static
    {
        return $this->state(fn (): array => [
            'status' => PipelineStepStatus::Failed,
            'output' => null,
            'error' => ['class' => 'RuntimeException', 'message' => $message, 'retryable' => false],
        ]);
    }

    /** A step that called no model, which is most of them. */
    public function unmetered(): static
    {
        return $this->state(fn (): array => [
            'provider' => null,
            'model' => null,
            'role' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_micros' => 0,
        ]);
    }

    public function withKey(string $key, int $position = 0): static
    {
        return $this->state(fn (): array => ['step_key' => $key, 'position' => $position]);
    }
}
