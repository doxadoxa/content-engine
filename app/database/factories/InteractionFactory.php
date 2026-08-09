<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChannelType;
use App\Enums\InteractionState;
use App\Models\Channel;
use App\Models\Interaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Interaction>
 */
class InteractionFactory extends Factory
{
    protected $model = Interaction::class;

    /**
     * As in {@see WebhookDeliveryFactory}, `channel_id` resolves first so the
     * conversation inherits its channel's project rather than resolving the
     * tenant twice — a reply in a project its own channel is not in would be
     * unanswerable.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $handle = fake()->userName();

        return [
            'channel_id' => Channel::factory()->social(ChannelType::Threads),
            'project_id' => fn (array $attributes): string => Channel::acrossProjects()
                ->whereKey($attributes['channel_id'])
                ->firstOrFail()
                ->project_id,
            'signal_id' => null,
            'external_id' => (string) fake()->unique()->randomNumber(9, true),
            'parent_external_id' => null,
            'root_external_id' => null,
            'author' => fake()->name(),
            'author_handle' => '@'.$handle,
            'text' => fake()->sentence(12),
            'permalink' => 'https://www.threads.net/@'.$handle.'/post/'.fake()->lexify('??????????'),
            'received_at' => Carbon::now()->subMinutes(fake()->numberBetween(1, 600)),
            'state' => InteractionState::New,
            'draft_reply' => null,
            'draft_generated_at' => null,
            'answered_at' => null,
            'reply_external_id' => null,
            'ignored_reason' => null,
            'raw' => ['id' => fake()->uuid()],
        ];
    }

    /**
     * Put the conversation straight into a state, with the columns that state
     * implies.
     *
     * The machine is what the state tests test, so this is the one place
     * allowed to skip it — but a row in `answered` with no `answered_at` is a
     * row the duty screen would report a latency of null for, so the stamps
     * come along.
     */
    public function inState(InteractionState $state): static
    {
        return $this->state(fn (): array => match ($state) {
            InteractionState::New => ['state' => $state],
            InteractionState::Drafted => [
                'state' => $state,
                'draft_reply' => fake()->sentence(10),
                'draft_generated_at' => Carbon::now()->subMinutes(5),
            ],
            InteractionState::Answered => [
                'state' => $state,
                'draft_reply' => fake()->sentence(10),
                'draft_generated_at' => Carbon::now()->subMinutes(10),
                'answered_at' => Carbon::now()->subMinutes(8),
                'reply_external_id' => (string) fake()->unique()->randomNumber(9, true),
            ],
            InteractionState::Ignored => [
                'state' => $state,
                'ignored_reason' => 'spam',
            ],
        });
    }
}
