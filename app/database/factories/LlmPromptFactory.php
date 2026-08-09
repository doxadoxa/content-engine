<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PromptIntent;
use App\Models\LlmPrompt;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmPrompt>
 */
class LlmPromptFactory extends Factory
{
    protected $model = LlmPrompt::class;

    /** So `count(n)` produces n distinct prompts rather than n unique-key violations. */
    private int $issued = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'text' => fn (): string => 'best cleaning service in Lisbon '.++$this->issued,
            'locale' => 'en',
            'intent' => PromptIntent::Buying,
            'is_active' => true,
        ];
    }

    public function inLocale(string $locale): static
    {
        return $this->state(fn (): array => ['locale' => $locale]);
    }

    public function withIntent(PromptIntent $intent): static
    {
        return $this->state(fn (): array => ['intent' => $intent]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
