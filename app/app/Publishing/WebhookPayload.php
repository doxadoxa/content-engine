<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\AssetRole;
use App\Enums\ContentFormat;
use App\Enums\WebhookEvent;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Builds the body the receiver sees (§3 of the contract).
 *
 * One locale per delivery, and `locale_group_id` alongside it — that pair is
 * what a receiver builds `hreflang` from, and it is why locales are separate
 * rows in §2 rather than translations hanging off one.
 *
 * The result is a plain array and is snapshotted verbatim onto the delivery
 * row. §6.2 makes that mandatory: without a snapshot, a replay sends today's
 * content and the receiver cannot tell a repeat from an edit.
 */
final class WebhookPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(ContentItem $unit, WebhookEvent $event, string $deliveryId): array
    {
        // loadMissing, not `$unit->project`: the relation is a lazy load, which
        // the application refuses on any model that came out of a query. The
        // webhook path never noticed because it is handed freshly-created
        // models; the pull API, which reads them back, 500s on the first row.
        $unit->loadMissing('project');

        $payload = [
            'contract' => (int) config('publishing.contract_version', 1),
            'event' => $event->value,
            'delivery_id' => $deliveryId,
            'sent_at' => now()->toIso8601String(),
            'project' => [
                'slug' => $unit->project->slug,
                'name' => $unit->project->name,
            ],
        ];

        if (! $event->carriesContent()) {
            return $payload;
        }

        $identity = [
            'id' => $unit->getKey(),
            'locale_group_id' => $unit->locale_group_id,
            'locale' => $unit->locale,
            'slug' => $unit->slug,
        ];

        // A delete carries identity only. Sending the body of something we are
        // asking the receiver to remove is at best confusing and at worst a
        // race with whatever it is meant to be deleting.
        if (! $event->carriesBody()) {
            return [...$payload, 'content' => $identity];
        }

        return [...$payload, 'content' => [
            ...$identity,
            'type' => $unit->type->value,
            'title' => $unit->title,
            'summary' => (string) $unit->summary,
            'markdown' => (string) $unit->body_markdown,
            'html' => (string) $unit->body_html,
            'published_at' => $unit->published_at?->toIso8601String(),
            'images' => self::images($unit),
            'json_ld' => $unit->json_ld,
            'faq_json_ld' => $unit->faq_json_ld,
            'author' => $unit->author,
            // Chosen by nearest neighbour over the corpus in phase 8. The key
            // has been in the contract since version 1, so filling it is not a
            // change receivers have to be told about.
            'internal_links' => $unit->internal_links,
        ]];
    }

    /**
     * A `ping` envelope, with no unit behind it.
     *
     * Separate from {@see for()} because a ping is genuinely not about a
     * content unit — and requiring one made it impossible to test a channel on
     * a project that had not generated anything yet, which is precisely when a
     * channel gets connected.
     *
     * @return array<string, mixed>
     */
    public static function ping(Project $project, string $deliveryId): array
    {
        return [
            'contract' => (int) config('publishing.contract_version', 1),
            'event' => WebhookEvent::Ping->value,
            'delivery_id' => $deliveryId,
            'sent_at' => now()->toIso8601String(),
            'project' => ['slug' => $project->slug, 'name' => $project->name],
        ];
    }

    public static function newDeliveryId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function images(ContentItem $unit): array
    {
        // What the post ships, not what it was choosing between. Variants are
        // live rows until one is promoted, so without this every rejected
        // candidate went out in the payload and a receiver rendering `images`
        // attached the drafts nobody picked.
        $roles = [AssetRole::Hero, AssetRole::Inline];

        // A carousel ships its panels and nothing else. The hero used to lead
        // them — `orderBy('role')` puts `hero` before `inline` — so the first
        // frame was a wordless photograph and the hook that earns the swipe was
        // second, on the one frame that has to stop a scroll. The cover panel
        // now draws *on* that photograph, so publishing it again would be the
        // same picture twice: once with the hook and once without, in that
        // order.
        //
        // The hero row stays exactly where it is. It is still what the operator
        // picks between candidates on the review screen, and still the source
        // {@see \App\Media\CarouselPanels} reads to draw the cover — it simply
        // stops being a frame of its own.
        // **The format, not the mere existence of inline assets.** Written first
        // as "has any inline row", which is true of every illustrated article:
        // {@see \App\Media\HeroImage::inline()} draws a picture per section with
        // exactly this role, and there are thirty-nine such articles in the
        // development database alone. That condition therefore stripped the hero
        // from every one of them — an article header, gone from the payload, on
        // a change whose entire subject was carousels.
        //
        // And still only when the panels are actually there. A carousel whose
        // slides all failed to draw has no cover to have absorbed the
        // photograph, so suppressing the hero would publish a post with no
        // pictures at all rather than one with the wrong first frame.
        $isCarousel = ($unit->channel_payload['format'] ?? null) === ContentFormat::Carousel->value;

        if ($isCarousel && $unit->assets()->where('role', AssetRole::Inline)->exists()) {
            $roles = [AssetRole::Inline];
        }

        /** @var list<array<string, mixed>> $images */
        $images = $unit->assets()
            ->whereIn('role', $roles)
            ->orderBy('role')
            ->get()
            ->map(static fn (Asset $asset): array => [
                'role' => $asset->role->value,
                'url' => $asset->url(),
                'alt' => $asset->alt,
                // Which heading the image belongs after. Null for the hero,
                // which has one place it can go.
                'anchor' => $asset->anchor,
                'width' => $asset->width,
                'height' => $asset->height,
            ])
            ->values()
            ->all();

        return $images;
    }
}
