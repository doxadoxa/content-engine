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

    public function social(ChannelType $type = ChannelType::LinkedIn): static
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'config' => ['handle' => '@'.fake()->userName()],
        ]);
    }

    /**
     * The Threads channel of §9: a target and toggles, and no credential.
     *
     * No secret, deliberately. §9 puts the token on `ProjectIntegration` — "
     * `Channel` держит только цель и тумблеры" — so a Threads channel carrying
     * one would be a second copy of a secret that is renewed elsewhere.
     *
     * The name is left to the definition's unique generator: channel names are
     * unique per project, and a state that hardcodes "Threads" makes a second
     * one in the same test a constraint violation rather than a second channel.
     */
    public function threads(): static
    {
        return $this->state(fn (): array => [
            'type' => ChannelType::Threads,
            'config' => ['handle' => '@'.fake()->userName()],
            'secret' => null,
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
