<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChannelType;
use App\Models\Channel;
use Database\Factories\Concerns\ResolvesProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    use ResolvesProject;

    protected $model = Channel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => fn (): mixed => $this->resolveProject(),
            'type' => ChannelType::Webhook,
            'name' => Str::headline(fake()->unique()->word().' '.fake()->word()),
            'config' => ['endpoint' => fake()->url()],
            'secret' => fake()->sha256(),
            'is_enabled' => true,
        ];
    }

    public function webhook(): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => fake()->url()],
        ]);
    }

    /** A channel configured but not yet given a token. */
    public function withoutSecret(): static
    {
        return $this->state(fn (): array => ['secret' => null]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }
}
