<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Models\ContentItem;
use Database\Factories\Concerns\ResolvesProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentItem>
 */
class ContentItemFactory extends Factory
{
    use ResolvesProject;

    protected $model = ContentItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::headline(sprintf(
            '%s %s %s %s',
            fake()->unique()->word(),
            fake()->word(),
            fake()->word(),
            fake()->word(),
        ));

        return [
            'project_id' => fn (): mixed => $this->resolveProject(),
            'content_plan_id' => null,
            'brand_brief_id' => null,
            'parent_id' => null,
            'locale_group_id' => (string) Str::ulid(),
            'locale' => 'en',
            'state' => ContentItemState::Idea,
            'type' => ContentItemType::HowTo,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'target_query' => sprintf('%s %s %s', fake()->word(), fake()->word(), fake()->word()),
            'entities' => [fake()->word(), fake()->word()],
            'topic_difficulty' => fake()->numberBetween(0, 100),
            'topic_volume' => fake()->numberBetween(10, 20_000),
            'published_at' => null,
        ];
    }

    /**
     * Put the item straight into a state.
     *
     * Tests that need a *drafted* item want one row, not five transitions —
     * and this is the only place allowed to skip the machine, because the
     * machine itself is what the state tests are testing.
     */
    public function inState(ContentItemState $state): static
    {
        return $this->state(fn (): array => [
            'state' => $state,
            'published_at' => $state->isLive() ? now() : null,
        ]);
    }

    public function draft(): static
    {
        return $this->inState(ContentItemState::Draft);
    }

    public function published(): static
    {
        return $this->inState(ContentItemState::Published);
    }

    public function locale(string $locale): static
    {
        return $this->state(fn (): array => ['locale' => $locale]);
    }

    /** Another locale of an existing unit: same group, different language. */
    public function inGroup(string $localeGroupId, string $locale): static
    {
        return $this->state(fn (): array => [
            'locale_group_id' => $localeGroupId,
            'locale' => $locale,
        ]);
    }

    /**
     * A social post derived from a parent. Derivatives inherit the parent's
     * entities and links (§2), so the factory copies them rather than inventing
     * unrelated ones.
     */
    public function derivedFrom(ContentItem $parent, ContentItemType $type = ContentItemType::SocialPost): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->getKey(),
            'project_id' => $parent->project_id,
            'content_plan_id' => $parent->content_plan_id,
            'locale' => $parent->locale,
            'type' => $type,
            'entities' => $parent->entities,
            'target_query' => $parent->target_query,
        ]);
    }
}
