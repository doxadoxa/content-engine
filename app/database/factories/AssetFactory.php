<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssetRole;
use App\Models\Asset;
use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * `content_item_id` is declared before `project_id` on purpose: attribute
     * closures are evaluated in order and are handed the keys resolved so far,
     * so the asset lands in whatever project its unit is in rather than
     * resolving the tenant a second time and risking a different answer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'project_id' => fn (array $attributes): string => ContentItem::acrossProjects()
                ->whereKey($attributes['content_item_id'])
                ->firstOrFail()
                ->project_id,
            'role' => AssetRole::Inline,
            'anchor' => fake()->slug(3),
            'disk' => 'public',
            'path' => 'assets/'.fake()->uuid().'.webp',
            'alt' => fake()->sentence(6),
            'width' => 1200,
            'height' => 675,
        ];
    }

    /** The single image at the top. At most one per unit — the DB says so. */
    public function hero(): static
    {
        return $this->state(fn (): array => [
            'role' => AssetRole::Hero,
            'anchor' => null,
            'width' => 1600,
            'height' => 900,
        ]);
    }

    public function inline(?string $anchor = null): static
    {
        return $this->state(fn (): array => [
            'role' => AssetRole::Inline,
            'anchor' => $anchor ?? fake()->slug(3),
        ]);
    }
}
