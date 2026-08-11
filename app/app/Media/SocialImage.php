<?php

declare(strict_types=1);

namespace App\Media;

use App\Enums\AssetRole;
use App\Enums\ContentItemType;
use App\Media\Contracts\ImageGenerationProvider;
use App\Models\Asset;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Support\Social\ChannelPlaybook;
use App\Support\Social\SocialImagePrompt;
use Illuminate\Support\Facades\DB;

/**
 * The picture a social post gets, which is not the picture an article gets.
 *
 * {@see HeroImage} makes an article's hero: one editorial image, 1200×630,
 * described to the provider as an illustration of a titled article. Every one
 * of those three decisions is wrong for a post. 1200×630 is an Open Graph card
 * — in a feed it is a letterbox strip, and Instagram crops it to a
 * fifth of the screen. "An article titled X" is a sentence about a document
 * nobody is reading. And a hero borrowed across locales is right for a
 * translated article and meaningless for a post that exists once.
 *
 * So this is a separate object rather than a fourth method on {@see HeroImage},
 * and the boundary is the one the two docblocks can each state: that one
 * illustrates a document, this one illustrates a post.
 *
 * **Size comes from the channel.** {@see ChannelPlaybook} carries it, so the
 * ratio the prompt composes for and the pixels the provider renders are one
 * decision read twice rather than two decisions that agree today.
 *
 * **A reference picture is passed when there is one to pass**, which is what
 * makes a month of posts look like one account. The provider's `edit` model
 * takes references and its text model does not, so this is also the difference
 * between paying for a set and paying for a pile — see
 * {@see AtlasSeedreamImageGeneration::generate()}. It is skipped when the URL
 * is not reachable from outside this machine, because a reference the provider
 * cannot fetch fails the whole generation rather than degrading it.
 */
class SocialImage
{
    public function __construct(private readonly ImageGenerationProvider $images) {}

    public function isConfigured(): bool
    {
        return $this->images->isConfigured();
    }

    /**
     * Draw this post's picture, unless it already has one.
     *
     * The existing-asset check is the same one {@see HeroImage::for()} makes
     * and for the same reason: a re-run of a batch that already produced its
     * drafts must not pay a second time for the same picture.
     *
     * @return array{asset: Asset, cost: int, provider: string|null, model: string|null}|null null when there is no provider
     */
    public function for(
        ContentItem $item,
        ChannelPlaybook $playbook,
        SocialImagePrompt $prompt,
        ?BrandBrief $brief = null,
        ?string $shot = null,
    ): ?array {
        if (! $this->images->isConfigured()) {
            return null;
        }

        $existing = $item->assets()->where('role', AssetRole::Hero)->first();

        if ($existing !== null) {
            return ['asset' => $existing, 'cost' => 0, 'provider' => null, 'model' => null];
        }

        $image = $this->images->generate(
            prompt: $prompt->build($playbook, $brief, $shot),
            references: $this->references($item),
            options: [
                'width' => $playbook->imageWidth,
                'height' => $playbook->imageHeight,
            ],
        );

        $asset = Asset::query()->create([
            'content_item_id' => $item->getKey(),
            'role' => AssetRole::Hero,
            'anchor' => null,
            'disk' => $image->disk,
            'path' => $image->path,
            // The post itself, which is what somebody using a screen reader
            // needs to hear — the title is the idea plus the channel's name and
            // says nothing about the picture.
            'alt' => $this->alt($item),
            'width' => $image->width,
            'height' => $image->height,
        ]);

        return [
            'asset' => $asset,
            'cost' => $image->costMicros,
            'provider' => $image->provider,
            'model' => $image->model,
        ];
    }

    /**
     * Draw candidates an operator will choose between.
     *
     * The deliberate difference from {@see for()} is that this does not stop
     * when a picture already exists — asking for another one is the whole
     * point. Until this existed there was no lever at all: the first image a
     * draft got was the image it kept, however wrong, because `for()` sees an
     * existing asset and returns it.
     *
     * They are stored as {@see AssetRole::Variant} rather than as heroes,
     * because `assets_one_hero_per_item` allows exactly one hero per item and
     * that constraint is worth keeping — see the enum. Nothing here touches the
     * current hero; {@see choose()} is where a decision happens, and until it
     * is made the post still ships what it already had.
     *
     * @return list<array{asset: Asset, cost: int, provider: string|null, model: string|null}>
     */
    public function variants(
        ContentItem $item,
        ChannelPlaybook $playbook,
        SocialImagePrompt $prompt,
        int $count,
        ?BrandBrief $brief = null,
        ?string $shot = null,
    ): array {
        if (! $this->images->isConfigured()) {
            return [];
        }

        $references = $this->references($item);
        $made = [];

        for ($index = 0; $index < max(1, $count); $index++) {
            $image = $this->images->generate(
                prompt: $prompt->variation($index)->build($playbook, $brief, $shot),
                references: $references,
                options: [
                    'width' => $playbook->imageWidth,
                    'height' => $playbook->imageHeight,
                ],
            );

            $made[] = [
                'asset' => Asset::query()->create([
                    'content_item_id' => $item->getKey(),
                    'role' => AssetRole::Variant,
                    'anchor' => null,
                    'disk' => $image->disk,
                    'path' => $image->path,
                    'alt' => $this->alt($item),
                    'width' => $image->width,
                    'height' => $image->height,
                ]),
                'cost' => $image->costMicros,
                'provider' => $image->provider,
                'model' => $image->model,
            ];
        }

        return $made;
    }

    /**
     * Make one of the candidates the picture this post ships.
     *
     * A swap and not a write: the chosen variant becomes the hero and the hero
     * it displaced becomes a superseded variant. Both halves matter. The first
     * is what the unique index requires — two rows cannot both be the hero — and
     * the second is what makes the decision reversible, which is the difference
     * between choosing and discarding. An operator who preferred the picture
     * they rejected an hour ago can still have it.
     *
     * The order is forced by the index: the sitting hero has to stop being one
     * before the new one can start, so this runs in a transaction rather than
     * as two saves that could be interrupted between.
     */
    public function choose(ContentItem $item, Asset $variant): Asset
    {
        return DB::transaction(function () use ($item, $variant): Asset {
            $current = $item->assets()->where('role', AssetRole::Hero)->first();

            if ($current !== null && $current->is($variant)) {
                return $current;
            }

            $current?->forceFill([
                'role' => AssetRole::Variant,
                'superseded_at' => now(),
            ])->save();

            $variant->forceFill([
                'role' => AssetRole::Hero,
                'superseded_at' => null,
            ])->save();

            return $variant;
        });
    }

    /**
     * The most recent picture this plan has already made, if any.
     *
     * One reference and not several: the `edit` model reads them as a combined
     * instruction, and handing it four unrelated posts' pictures asks for the
     * average of the month rather than the look of it. The newest is the right
     * one because it is the end of whatever the account currently looks like.
     *
     * Scoped to the plan rather than to the project so that a new month can
     * look different on purpose — a plan is where an operator changes the
     * direction, and inheriting from the previous month would quietly refuse
     * the change.
     *
     * @return list<string>
     */
    private function references(ContentItem $item): array
    {
        if ($item->content_plan_id === null) {
            return [];
        }

        $previous = Asset::query()
            ->where('role', AssetRole::Hero)
            ->whereNull('superseded_at')
            ->whereIn(
                'content_item_id',
                ContentItem::query()
                    ->select('id')
                    ->where('content_plan_id', $item->content_plan_id)
                    // Social posts only. A `ContentPlan` is one row per project
                    // per month and the article calendar is filed under the
                    // same one, so without this the newest thing in the plan is
                    // often an article's 1200×630 hero from
                    // {@see HeroImage::for()} — and handing that to the `edit`
                    // model as the style reference propagates the exact look
                    // this class exists to stop producing.
                    ->where('type', ContentItemType::SocialPost)
                    ->whereKeyNot($item->getKey()),
            )
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            return [];
        }

        $url = $previous->url();

        return PublicUrl::isFetchable($url) ? [$url] : [];
    }

    /**
     * What the picture is of, in a sentence a screen reader can use.
     *
     * The post's own opening line, trimmed. It is the closest thing to a
     * description of the image that exists at this point — the picture was
     * drawn from the post — and it is certainly closer than "Idea title —
     * Instagram", which is what was being stored.
     */
    private function alt(ContentItem $item): string
    {
        $body = trim((string) $item->body_markdown);

        if ($body === '') {
            return $item->title;
        }

        $first = trim((string) (preg_split('/\R/u', $body) ?: [''])[0]);

        return $first === '' ? $item->title : mb_substr($first, 0, 255);
    }
}
