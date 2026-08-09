<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContentItem;
use App\Models\ContentMetric;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ContentMetric>
 */
class ContentMetricFactory extends Factory
{
    protected $model = ContentMetric::class;

    /** Days handed out by this instance, so `count(n)` is n consecutive days. */
    private int $issued = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory()->published(),
            'project_id' => fn (array $attributes): string => ContentItem::acrossProjects()
                ->whereKey($attributes['content_item_id'])
                ->firstOrFail()
                ->project_id,
            // Consecutive days: (content_item_id, measured_on) is unique, and a
            // fixed date would collide on the second row.
            'measured_on' => fn (): Carbon => Carbon::today()->subDays($this->issued++),
            'impressions' => fake()->numberBetween(50, 5_000),
            'clicks' => fake()->numberBetween(0, 200),
            'position_tenths' => fake()->numberBetween(10, 400),
            'indexed' => true,
        ];
    }

    public function on(string $day): static
    {
        return $this->state(fn (): array => ['measured_on' => Carbon::parse($day)]);
    }

    public function unindexed(): static
    {
        return $this->state(fn (): array => [
            'indexed' => false,
            'impressions' => 0,
            'clicks' => 0,
            'position_tenths' => null,
        ]);
    }
}
