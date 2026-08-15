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

        foreach ($slides as $index => $slide) {
            $position = $index + 1;
            $layout = SlideLayout::tryFrom((string) ($slide['layout'] ?? ''));

            try {
                $bytes = $this->renderer->render(
                    // The flat template where a slide names no layout, which is
                    // every carousel written before layouts existed. Those posts
                    // are redrawn as what they were, not reinterpreted.
                    composition: $layout?->composition() ?? 'panel',
                    props: $this->props($layout, $slide, $position, $total, $style),
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
            Storage::disk((string) config('media.disk', 'public'))->put($path, $bytes);

            $drawn[] = Asset::query()->create([
                'content_item_id' => $item->getKey(),
                'role' => AssetRole::Inline,
                'source' => AssetSource::Rendered,
                'anchor' => $this->anchor($position),
                'disk' => (string) config('media.disk', 'public'),
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
