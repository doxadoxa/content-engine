<?php

declare(strict_types=1);

namespace App\Media;

use App\Enums\AssetRole;
use App\Enums\AssetSource;
use App\Enums\SlideLayout;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Support\Brand\VisualStyle;
use App\Support\Social\ChannelPlaybook;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A teaching carousel's slides, as pictures.
 *
 * This is what the renderer was stood up for. A `how_to` post is a sequence of
 * steps, and until now the sequence existed only as text: the slides were
 * written, concatenated into the body, and the post shipped with one generated
 * photograph attached to the whole thing. On Instagram — the channel a carousel
 * is native to, and the one that pays for the format — the reader saw a
 * photograph of a kitchen and had to open the caption to find the steps.
 *
 * **Each slide is its own asset, at the position it occupies.** Stored as
 * {@see AssetRole::Inline} with the slide's index as the anchor, which is what
 * that role already means — a picture that belongs at a place in the body
 * rather than at the top of it. No new role and no migration: a carousel panel
 * is the same idea as a section illustration, arriving at a different kind of
 * unit.
 *
 * **Re-rendering supersedes rather than duplicates.** A draft whose text is
 * revised has to be able to redraw its panels, and slides that kept
 * accumulating would leave the publisher choosing between four pictures for
 * slide two. The old rows stay — nothing in this engine deletes an asset — so a
 * panel can be looked at after it has been replaced.
 *
 * **One failed slide does not lose the rest.** A carousel with six panels and a
 * seventh that would not draw is still a carousel; failing the batch would
 * throw away six pictures to punish one.
 */
class CarouselPanels
{
    public function __construct(private readonly PanelRenderer $renderer) {}

    public function isConfigured(): bool
    {
        return $this->renderer->isConfigured();
    }

    /**
     * Draw this carousel's slides and keep them against the draft.
     *
     * @param  list<array{heading: string, body: string, layout?: string|null, fields?: array<string, mixed>}>  $slides
     * @return list<Asset>
     */
    public function draw(
        ContentItem $item,
        ChannelPlaybook $playbook,
        array $slides,
        VisualStyle $style,
    ): array {
        if (! $this->isConfigured() || $slides === []) {
            return [];
        }

        $drawn = [];
        $total = count($slides);

        // Read once rather than per slide: only the cover uses it, and a hero is
        // most of a megabyte that would otherwise be loaded eight times to be
        // thrown away seven.
        // A brand that opens its carousels on its own colour does not read the
        // photograph at all — not merely hides it. The picture is still bought
        // and still published for every other format; it simply is not what
        // slide one is drawn on.
        $hero = $style->cover === 'photo' ? $this->coverPhoto($item) : null;
        $photo = $hero['uri'] ?? null;

        // From the same bytes that were just read. Deciding this per slide, or
        // from a second read, would be a megabyte of disk and a second decode
        // for an answer that cannot differ between slides of one carousel.
        $anchor = $hero === null ? 'bottom' : PhotoAnchor::for($hero['bytes']);

        foreach ($slides as $index => $slide) {
            $position = $index + 1;
            $layout = SlideLayout::tryFrom((string) ($slide['layout'] ?? ''));

            try {
                $bytes = $this->renderer->render(
                    // The flat template where a slide names no layout, which is
                    // every carousel written before layouts existed. Those posts
                    // are redrawn as what they were, not reinterpreted.
                    composition: $layout?->composition() ?? 'panel',
                    props: $this->props($layout, $slide, $position, $total, $style, $photo, $anchor),
                    width: $playbook->imageWidth,
                    height: $playbook->imageHeight,
                );
            } catch (RetryableStepFailure|TerminalStepFailure) {
                // Both, and that is the correction. Catching only the terminal
                // kind let a refused connection — the renderer still bundling,
                // a host that blinked — escape into the caller and fail a run
                // whose drafts were already written and paid for. The retry
                // then found every channel drafted, returned "created: 0" and
                // never illustrated anything, so one blip produced a carousel
                // with no pictures and no error anybody could see.
                continue;
            }

            $path = 'panels/'.Str::random(24).'.png';

            try {
                MediaDisk::put($path, $bytes);
            } catch (MediaWriteFailed $e) {
                // The same conclusion as the renderer catch above, for the same
                // reason. Raising here would escape into `generateIdea`, which
                // has already persisted every draft and holds a lock rather than
                // a transaction — so the retry finds nothing missing, answers
                // `created: 0`, and the carousel never gets pictures at all.
                // Skipping costs this one panel and no Asset row, which is the
                // failure the caller can actually survive.
                Log::warning('A carousel panel could not be stored', [
                    'item' => $item->getKey(),
                    'path' => $path,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            $drawn[] = Asset::query()->create([
                'content_item_id' => $item->getKey(),
                'role' => AssetRole::Inline,
                'source' => AssetSource::Rendered,
                'anchor' => $this->anchor($position),
                'disk' => MediaDisk::name(),
                'path' => $path,
                // The slide's own heading, which is what a screen reader needs
                // and what the panel actually shows.
                'alt' => Str::limit($slide['heading'], 255, ''),
                'width' => $playbook->imageWidth,
                'height' => $playbook->imageHeight,
            ]);
        }

        // Retired only once something replaced them. Retiring up front meant a
        // redraw where every slide then failed left the post with no panels at
        // all — worse than the ones it had.
        if ($drawn !== []) {
            $this->retire($item, $drawn);
        }

        return $drawn;
    }

    /**
     * The slides this draft currently ships, in order.
     *
     * @return list<Asset>
     */
    public function current(ContentItem $item): array
    {
        return array_values($item->assets()
            ->where('role', AssetRole::Inline)
            ->whereNull('superseded_at')
            ->orderBy('anchor')
            ->get()
            ->all());
    }

    /**
     * The photograph the cover is set on, as bytes rather than as an address.
     *
     * **A data URI, and it has to be.** The renderer is pinned: every URL it
     * opens is one the caller resolved and vetted, and Chromium inside it is
     * restricted to those addresses precisely so a template cannot be talked
     * into fetching something on the compose network. A `storage/` URL is
     * exactly the shape that pin exists to refuse, so handing over the bytes is
     * not a shortcut around the guard — it is the only way through it that does
     * not weaken it. The renderer's own docblock already settles the trade for
     * the return leg: "an image is a few hundred kilobytes; HTTP is the boring
     * option and the one that survives the move."
     *
     * The chosen hero, not a candidate. `Variant` rows are what an operator is
     * still deciding between, and a cover drawn on a picture nobody picked would
     * change the moment they picked one.
     *
     * Null on every path that is not a photograph this can actually read — no
     * hero, a file the disk has lost, an empty object. The cover then draws as
     * it always did, which is a complete slide rather than a broken one.
     */
    /**
     * @return array{bytes: string, uri: string}|null
     */
    private function coverPhoto(ContentItem $item): ?array
    {
        $hero = $item->assets()
            ->where('role', AssetRole::Hero)
            ->whereNull('superseded_at')
            ->latest()
            ->first();

        if ($hero === null) {
            return null;
        }

        $disk = Storage::disk($hero->disk);

        if (! $disk->exists($hero->path)) {
            return null;
        }

        $bytes = $disk->get($hero->path);

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        // From the extension rather than sniffed: these are files this engine
        // wrote itself, and a wrong type here is a picture that silently does
        // not decode in the template.
        $mime = str_ends_with(strtolower($hero->path), '.png') ? 'image/png' : 'image/jpeg';

        // Both, because two different things want this picture: the template
        // wants something an <img> can take, and PhotoAnchor wants pixels.
        return [
            'bytes' => $bytes,
            'uri' => 'data:'.$mime.';base64,'.base64_encode($bytes),
        ];
    }

    /**
     * One slide's props, as the layout that draws it expects them.
     *
     * The brand half is identical for every layout and the content half is not,
     * which is the whole reason this is a method: a template reading a field the
     * parser never sends draws a blank band, and blank is the one failure a
     * renderer reports as success.
     *
     * `heading` goes to every layout including the ones whose component ignores
     * it — `stat` draws `caption` and `contrast` draws its two halves — because
     * it costs nothing and a layout that later wants it does not need this
     * touched.
     *
     * @param  array{heading: string, body: string, layout?: string|null, fields?: array<string, mixed>}  $slide
     * @return array<string, mixed>
     */
    private function props(
        ?SlideLayout $layout,
        array $slide,
        int $position,
        int $total,
        VisualStyle $style,
        ?string $photo = null,
        string $anchor = 'bottom',
    ): array {
        $heading = $style->write($slide['heading']);

        $props = [
            'heading' => $heading,
            'body' => trim($slide['body']),
            'index' => $position,
            'total' => $total,
            'colour' => $style->colour,
            'ink' => $style->ink,
            'position' => $style->position,
            // The brief's own third colour, which resolves to the ink where a
            // brand has not named one. It used to be the ink unconditionally,
            // and that was right while there was one template with a rule on
            // it: with two colours, emphasis is impossible — the figure on a
            // `stat` and the half of a `contrast` that matters both had to be
            // drawn in the same colour as everything around them.
            'accent' => $style->accent,
            // What may be written *on* the accent, and what the accent may be
            // written *with*. Computed here because a template knows it is
            // filling a band with the accent and cannot know whether this
            // brand's accent carries type — see VisualStyle::readableOn().
            'onAccent' => $style->readableOn($style->accent),
            'accentType' => $style->accentType($style->colour),
        ];

        // The colour a highlighted run of the heading is drawn in, decided here
        // for the same reason `accentType` is: the template knows it is setting
        // type at 124px and cannot know whether this brand's accent survives on
        // its own fill. A cover whose highlight is unreadable is worse than one
        // with no highlight, because the words it picked out are the ones the
        // sentence turns on.
        $props['highlightInk'] = $style->accentType($style->colour);

        // The slug names the directory the woff2 files sit in; the family is
        // what CSS asks for. Both, because the template needs to @font-face one
        // and set the other, and deriving either from the other in JSX would put
        // the mapping in two places.
        // The cover, and only the cover. The photograph used to publish as a
        // frame of its own ahead of the panels — so the first thing anybody saw
        // was a wordless picture and the hook that earns the swipe was second.
        // Now it is the ground the hook is set on, which is the only arrangement
        // where a carousel gets both.
        if ($layout === SlideLayout::Cover && $photo !== null) {
            $props['photo'] = $photo;
            // Which end of the picture is empty enough to stand type on. See
            // {@see PhotoAnchor}: the scrim was fixed to the foot, and the
            // first photograph it met had its whole subject down there.
            $props['photoAnchor'] = $anchor;
        }

        $props['typeface'] = $style->typeface;
        $props['typefaceFamily'] = VisualStyle::TYPEFACES[$style->typeface]
            ?? VisualStyle::TYPEFACES[VisualStyle::DEFAULT_TYPEFACE];

        foreach (($slide['fields'] ?? []) as $field => $value) {
            $props[$field] = is_string($value) ? $style->write($value) : $value;
        }

        // The one place a field is renamed on the way out. `stat` draws the
        // heading beneath its figure, and calling that slot `caption` in the
        // component is right — it is a caption to a number — while calling it
        // `caption` in the parser would collide with the post's own caption.
        if ($layout === SlideLayout::Stat) {
            $props['caption'] = $heading;
        }

        return $props;
    }

    /**
     * Two digits, so ten slides sort after nine rather than between one and two.
     *
     * A string column ordered as a string, which is the trap this avoids. It is
     * worth the two characters: the failure it prevents is a carousel that
     * publishes its steps in the order 1, 10, 2, 3.
     */
    private function anchor(int $position): string
    {
        return 'slide-'.str_pad((string) $position, 2, '0', STR_PAD_LEFT);
    }

    /** @param list<Asset> $keep */
    private function retire(ContentItem $item, array $keep): void
    {
        $item->assets()
            ->where('role', AssetRole::Inline)
            ->whereNull('superseded_at')
            ->whereKeyNot(array_map(static fn (Asset $asset): string => (string) $asset->getKey(), $keep))
            ->update(['superseded_at' => now()]);
    }
}
