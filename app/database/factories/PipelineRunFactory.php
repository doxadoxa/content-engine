<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PipelineRunStatus;
use App\Models\PipelineRun;
use Database\Factories\Concerns\ResolvesProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PipelineRun>
 */
class PipelineRunFactory extends Factory
{
    use ResolvesProject;

    protected $model = PipelineRun::class;

    /**
     * The totals here are the header's, not derived from step rows — a factory
     * builds a plausible row, and {@see PipelineRun::rollUpTotals()} is what
     * makes them agree with real steps.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = Carbon::now()->subMinutes(fake()->numberBetween(1, 240));
        $latency = fake()->numberBetween(2_000, 90_000);

        return [
            'project_id' => fn (): mixed => $this->resolveProject(),
            'content_item_id' => null,
            'pipeline' => 'demo',
            'pipeline_version' => 1,
            'status' => PipelineRunStatus::Completed,
            'input' => ['topic' => implode(' ', [fake()->word(), fake()->word()])],
            'context' => [],
            'price_list_version' => 1,
            'input_tokens' => fake()->numberBetween(1_000, 40_000),
            'output_tokens' => fake()->numberBetween(500, 12_000),
            'cost_micros' => fake()->numberBetween(1_000, 900_000),
            'latency_ms' => $latency,
            'error' => null,
            'failed_step_key' => null,
            'started_at' => $started,
            'finished_at' => $started->copy()->addMilliseconds($latency),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => PipelineRunStatus::Pending,
            'started_at' => null,
            'finished_at' => null,
            'latency_ms' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_micros' => 0,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => PipelineRunStatus::Running,
            'finished_at' => null,
            'latency_ms' => null,
        ]);
    }

    public function failed(string $step = 'summarise_topic'): static
    {
        return $this->state(fn (): array => [
            'status' => PipelineRunStatus::Failed,
            'failed_step_key' => $step,
            'error' => [
                'class' => 'RuntimeException',
                'message' => 'step `'.$step.'` exhausted its retries',
                'retryable' => true,
            ],
        ]);
    }

    public function forPipeline(string $key, int $version = 1): static
    {
        return $this->state(fn (): array => ['pipeline' => $key, 'pipeline_version' => $version]);
    }
}
