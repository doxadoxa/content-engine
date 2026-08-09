<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\Channel;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * As in {@see AssetFactory}, `channel_id` resolves first so the delivery
     * inherits its channel's project rather than resolving the tenant twice.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'project_id' => fn (array $attributes): string => Channel::acrossProjects()
                ->whereKey($attributes['channel_id'])
                ->firstOrFail()
                ->project_id,
            'content_item_id' => null,
            'delivery_id' => fake()->uuid(),
            'status' => DeliveryStatus::Delivered,
            'response_code' => 200,
            'latency_ms' => fake()->numberBetween(40, 3_000),
            'attempts' => 1,
            'payload_snapshot' => ['title' => fake()->sentence(5)],
            'error' => null,
            'delivered_at' => Carbon::now(),
            'next_attempt_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Pending,
            'response_code' => null,
            'latency_ms' => null,
            'attempts' => 0,
            'delivered_at' => null,
        ]);
    }

    /** Failed and waiting on a retry — the row phase 6's backoff reads. */
    public function failed(int $code = 500): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Retrying,
            'response_code' => $code,
            'attempts' => fake()->numberBetween(1, 4),
            'error' => "receiver answered {$code}",
            'delivered_at' => null,
            'next_attempt_at' => Carbon::now()->addMinutes(5),
        ]);
    }
}
