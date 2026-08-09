<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LlmPrompt;
use App\Models\LlmVisibilityAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LlmVisibilityAnswer>
 */
class LlmVisibilityAnswerFactory extends Factory
{
    protected $model = LlmVisibilityAnswer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'llm_prompt_id' => LlmPrompt::factory(),
            'project_id' => fn (array $attributes): string => LlmPrompt::acrossProjects()
                ->whereKey($attributes['llm_prompt_id'])
                ->firstOrFail()
                ->project_id,
            'platform' => 'chat_gpt',
            'model' => 'fake-model',
            'asked_on' => fn (): Carbon => Carbon::today(),
            'mentioned' => false,
            'excerpt' => 'Several providers cover this.',
            'citations' => [],
            'brands' => [],
            'money_spent' => 0.001,
        ];
    }

    public function mentioned(): static
    {
        return $this->state(fn (): array => ['mentioned' => true]);
    }

    /** The assistant was asked and declined — not the same as answering without us. */
    public function declined(): static
    {
        return $this->state(fn (): array => ['mentioned' => null, 'excerpt' => null]);
    }

    public function on(string $platform): static
    {
        return $this->state(fn (): array => ['platform' => $platform]);
    }
}
