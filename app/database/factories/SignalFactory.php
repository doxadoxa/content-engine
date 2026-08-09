<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SignalKind;
use App\Enums\SignalSource;
use App\Models\Signal;
use Database\Factories\Concerns\ResolvesProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Signal>
 */
class SignalFactory extends Factory
{
    use ResolvesProject;

    protected $model = Signal::class;

    /**
     * The fingerprint is computed rather than faked. It is a function of the
     * title and the entities, and a random one would make every dedup test
     * pass for the wrong reason.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->sentence(6));
        $entities = [fake()->word(), fake()->word()];

        return [
            'project_id' => fn (): mixed => $this->resolveProject(),
            'kind' => SignalKind::Question,
            'source' => SignalSource::ThreadsKeywordSearch,
            'external_id' => (string) fake()->unique()->randomNumber(9, true),
            'fingerprint' => Signal::fingerprintFor($title, $entities),
            'title' => $title,
            'url' => fake()->url(),
            'entities' => $entities,
            'occurred_at' => Carbon::now()->subHours(fake()->numberBetween(1, 48)),
            'expires_at' => null,
            'weight' => fake()->numberBetween(0, 100),
            'raw' => ['id' => fake()->uuid()],
            'consumed_at' => null,
        ];
    }

    /** Already used by a plan or a draft, so out of {@see Signal::scopeLive()}. */
    public function consumed(): static
    {
        return $this->state(fn (): array => ['consumed_at' => Carbon::now()->subHour()]);
    }

    /** Past its TTL — the reactive band's normal end (§5). */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'occurred_at' => Carbon::now()->subDays(3),
            'expires_at' => Carbon::now()->subDay(),
        ]);
    }

    public function kind(SignalKind $kind): static
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }
}
