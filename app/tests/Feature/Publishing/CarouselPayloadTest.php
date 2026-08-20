<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\AssetRole;
use App\Enums\ContentItemType;
use App\Enums\WebhookEvent;
use App\Media\HeroImage;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Models\Project;
use App\Publishing\WebhookPayload;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which pictures a payload ships, now that a carousel's cover contains its own.
 *
 * The cover panel is drawn *on* the post's photograph, so publishing the hero as
 * well would send the same picture twice — once with the hook and once without,
 * in that order, because `orderBy('role')` puts `hero` first.
 *
 * The second test here is the one that matters. Suppressing the hero was first
 * written as "this unit has an inline asset", which is true of every illustrated
 * article — {@see HeroImage::inline()} uses the same role for section
 * pictures — and would have taken the header off every article in the corpus on
 * a change whose subject was carousels.
 */
final class CarouselPayloadTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function a_carousel_ships_its_panels_and_not_the_photograph_they_are_drawn_on(): void
    {
        $unit = $this->unit(['format' => 'carousel'], ContentItemType::SocialPost);

        $this->picture($unit, AssetRole::Hero);
        $this->picture($unit, AssetRole::Inline, 'slide-01');
        $this->picture($unit, AssetRole::Inline, 'slide-02');

        $roles = $this->rolesIn($unit);

        $this->assertSame(['inline', 'inline'], $roles);
    }

    /**
     * An illustrated article keeps its header.
     *
     * The regression guard: article sections are `AssetRole::Inline` too, so a
     * rule keyed on that role alone silently removes the hero a receiver builds
     * the article header from.
     */
    #[Test]
    public function an_illustrated_article_keeps_the_hero_its_header_is_built_from(): void
    {
        $unit = $this->unit([], ContentItemType::HowTo);

        $this->picture($unit, AssetRole::Hero);
        $this->picture($unit, AssetRole::Inline, 'section-01');
        $this->picture($unit, AssetRole::Inline, 'section-02');

        $roles = $this->rolesIn($unit);

        $this->assertContains('hero', $roles, 'An article without its hero has no header picture.');
        $this->assertCount(3, $roles);
    }

    /**
     * And a carousel whose slides never drew keeps the photograph it has.
     *
     * There is no cover to have absorbed it, so removing the hero would publish
     * a post with no pictures rather than one with the wrong first frame.
     */
    #[Test]
    public function a_carousel_with_no_drawn_panels_still_ships_its_photograph(): void
    {
        $unit = $this->unit(['format' => 'carousel'], ContentItemType::SocialPost);

        $this->picture($unit, AssetRole::Hero);

        $this->assertSame(['hero'], $this->rolesIn($unit));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function unit(array $payload, ContentItemType $type): ContentItem
    {
        return app(CurrentProject::class)->run(
            $this->project,
            fn (): ContentItem => ContentItem::factory()->create([
                'type' => $type,
                'channel_payload' => $payload,
            ]),
        );
    }

    private function picture(ContentItem $unit, AssetRole $role, ?string $anchor = null): void
    {
        app(CurrentProject::class)->run($this->project, function () use ($unit, $role, $anchor): void {
            Asset::factory()->create([
                'content_item_id' => $unit->getKey(),
                'role' => $role,
                'anchor' => $anchor,
            ]);
        });
    }

    /**
     * @return list<string>
     */
    private function rolesIn(ContentItem $unit): array
    {
        return app(CurrentProject::class)->run($this->project, function () use ($unit): array {
            $fresh = ContentItem::query()->whereKey($unit->getKey())->firstOrFail();

            $payload = WebhookPayload::for($fresh, WebhookEvent::Published, WebhookPayload::newDeliveryId());

            /** @var list<array<string, mixed>> $images */
            $images = $payload['content']['images'];

            return array_map(static fn (array $image): string => (string) $image['role'], $images);
        });
    }
}
