<?php

declare(strict_types=1);

namespace App\Media;

use App\Enums\AssetRole;
use App\Media\Contracts\ImageGenerationProvider;
use App\Models\Asset;
use App\Models\ContentItem;
use Illuminate\Support\Str;

/**
 * Making one hero image for one unit.
 *
 * Shared, because two pipelines want it: generation, so an article arrives with
 * its picture, and repurpose, so a social card has something to show. It lived
 * inside the repurpose step and nowhere else, which is why a finished article
 * had no image at all until somebody ran a second pipeline over it.
 *
 * Nothing here draws a picture the project already owns. Two lookups come
 * before every generate call: the unit's own assets, and then the other locales
 * of the same unit ({@see borrow()}) — a translation is a different article and
 * the same photograph.
 */
class HeroImage
{
    public function __construct(private readonly ImageGenerationProvider $images) {}

    public function isConfigured(): bool
    {
        return $this->images->isConfigured();
    }

    /**
     * A picture for one section, made to match the article's hero.
     *
     * The hero is passed as a reference so a set of four images looks like one
     * article rather than four unrelated illustrations — which is what the
     * `edit` model in config exists for, and why a first image and a follow-up
     * image are literally different models.
     *
     * `$position` is which illustrated section this is, counting from zero. It
     * is not stored and it is not an ordering — it exists only to ask a sibling
     * locale for the picture it put in the same place. See {@see borrow()}.
     *
     * @param  list<string>  $references  URLs of images already made for this unit
     * @return array{asset: Asset, cost: int}|null
     */
    public function inline(
        ContentItem $unit,
        string $heading,
        string $topic,
        array $references = [],
        ?int $position = null,
    ): ?array {
        if (! $this->images->isConfigured()) {
            return null;
        }

        if ($position !== null) {
            $borrowed = $this->borrow($unit, AssetRole::Inline, $position, Str::slug($heading), $heading);

            if ($borrowed !== null) {
                return ['asset' => $borrowed, 'cost' => 0];
            }
        }

        $image = $this->images->generate(
            prompt: implode("\n\n", [
                "A photograph illustrating this part of an article about {$topic}: {$heading}",
                'Show the subject being done or the materials involved. Natural light, real '
                .'setting, no people looking at the camera.',
                'No text, no logos, no words in the image.',
            ]),
            references: $references,
            options: [
                'width' => (int) config('media.inline.width', 1200),
                'height' => (int) config('media.inline.height', 800),
            ],
        );

        $asset = Asset::query()->create([
            'content_item_id' => $unit->getKey(),
            'role' => AssetRole::Inline,
            // The heading it belongs under, so a second run can tell which
            // sections already have a picture.
            'anchor' => Str::slug($heading),
            'disk' => $image->disk,
            'path' => $image->path,
            // Alt text is the heading, which is what the section is about and
            // what a screen reader needs to hear.
            'alt' => $heading,
            'width' => $image->width,
            'height' => $image->height,
        ]);

        return ['asset' => $asset, 'cost' => $image->costMicros];
    }

    /**
     * The unit's hero, made if it has none.
     *
     * At most one per unit — the database says so, and a second call would
     * spend money to hit a unique index.
     *
     * @return array{asset: Asset, cost: int}|null null when there is no provider
     */
    public function for(ContentItem $unit, string $title, ?string $summary): ?array
    {
        if (! $this->images->isConfigured()) {
            return null;
        }

        $existing = $unit->assets()->where('role', AssetRole::Hero)->first();

        if ($existing !== null) {
            return ['asset' => $existing, 'cost' => 0];
        }

        $borrowed = $this->borrow($unit, AssetRole::Hero, 0, null, $title);

        if ($borrowed !== null) {
            return ['asset' => $borrowed, 'cost' => 0];
        }

        $image = $this->images->generate(
            // Built from the title and summary rather than from the body: an
            // illustration should be about the subject, and the body is
            // thousands of words of it.
            prompt: implode("\n\n", array_filter([
                "An editorial image for an article titled: {$title}",
                $summary,
                'No text, no logos, no words in the image.',
            ])),
            options: [
                'width' => (int) config('media.hero.width', 1200),
                'height' => (int) config('media.hero.height', 630),
            ],
        );

        $asset = Asset::query()->create([
            'content_item_id' => $unit->getKey(),
            'role' => AssetRole::Hero,
            'anchor' => null,
            'disk' => $image->disk,
            'path' => $image->path,
            'alt' => $title,
            'width' => $image->width,
            'height' => $image->height,
        ]);

        return ['asset' => $asset, 'cost' => $image->costMicros];
    }

    /**
     * Take a picture another locale of this unit already paid for.
     *
     * §2 makes a locale a unit of its own — planned, dated and costed like any
     * other — and that is right for the *writing*. It was silently also true of
     * the pictures, and there it is nonsense: a photograph of a post-renovation
     * clean is the same photograph in Portuguese, English and Russian. Only the
     * alt text is language-specific, and alt lives on this row rather than on
     * the file. A three-locale project was paying for twelve images where four
     * would do, every article, forever.
     *
     * A new row pointing at the same `disk` and `path`, not a shared row: the
     * asset belongs to the unit it illustrates, the publish payload reads it
     * per unit, and `alt` and `anchor` have to be this locale's. Nothing in the
     * engine deletes a generated file, so two rows over one object stay valid.
     *
     * `$position` is matched against the sibling's inline pictures **in the
     * order they were made**, which is document order — {@see
     * \App\Pipelines\Steps\Generation\IllustrateDraft} walks the headings top to
     * bottom. That mapping is only ever consumed here, in the seconds after the
     * sibling finished, which is why it is derived rather than stored: the
     * assets migration refuses a position column for the good reason that a
     * stored index is wrong the moment phase 9 rewrites section order. This
     * needs the order the images were *drawn* in, once, and never reads it
     * again.
     *
     * ULIDs sort by creation time, so `orderBy('id')` is that order.
     */
    private function borrow(
        ContentItem $unit,
        AssetRole $role,
        int $position,
        ?string $anchor,
        string $alt,
    ): ?Asset {
        $siblings = $unit->localeVariants()->whereKeyNot($unit->getKey())->select('id');

        // One sibling supplies the whole set, and it is the one with the most
        // pictures of this role. Indexing across every sibling at once would
        // read position 2 out of a locale that only managed two images and hand
        // back the *next* locale's first — the same picture twice in one
        // article, under two different headings.
        $source = Asset::query()
            ->where('role', $role)
            ->whereIn('content_item_id', $siblings)
            ->selectRaw('content_item_id, count(*) as drawn')
            ->groupBy('content_item_id')
            ->orderByDesc('drawn')
            // Deterministic when two locales drew the same number, so a
            // re-illustrated unit borrows from where it borrowed before.
            ->orderBy('content_item_id')
            ->first();

        if ($source === null) {
            return null;
        }

        /** @var Asset|null $borrowed */
        $borrowed = Asset::query()
            ->where('role', $role)
            ->where('content_item_id', $source->getAttribute('content_item_id'))
            ->orderBy('id')
            ->skip($position)
            ->first();

        // That locale has fewer sections than this one. The remaining headings
        // get pictures of their own rather than a repeat.
        if ($borrowed === null) {
            return null;
        }

        return Asset::query()->create([
            'content_item_id' => $unit->getKey(),
            'role' => $role,
            'anchor' => $anchor,
            'disk' => $borrowed->disk,
            'path' => $borrowed->path,
            'alt' => $alt,
            'width' => $borrowed->width,
            'height' => $borrowed->height,
        ]);
    }
}
