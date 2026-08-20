<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SocialKpi;
use App\Models\ContentGoal;
use Database\Factories\Concerns\ResolvesProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ContentGoal>
 */
class ContentGoalFactory extends Factory
{
    use ResolvesProject;

    protected $model = ContentGoal::class;

    /**
     * Unconfirmed by default.
     *
     * The confirmation is a person's decision and the Overview branches on it,
     * so a factory that stamped it would make the setup state — the one an
     * operator actually meets first — the harder of the two to write a test
     * for.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => fn (): mixed => $this->resolveProject(),
            'month' => Carbon::now()->startOfMonth(),
            'kpi' => SocialKpi::Engagement,
            'target' => 500,
            'cadence' => 3,
            'weeks' => [],
            'confirmed_at' => null,
        ];
    }

    public function forMonth(Carbon|string $month): static
    {
        return $this->state(fn (): array => [
            'month' => Carbon::parse($month)->startOfMonth(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'confirmed_at' => Carbon::now(),
        ]);
    }
}
