<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ContentIdea;

/**
 * What an idea is *made as*, when somebody says so.
 *
 * Until now this was derived and never chosen: {@see PostKind::instagramFormat()}
 * returns `carousel` for a how-to and `image` for everything else, so a carousel
 * happened only on Instagram and only for one of the five kinds. Every other
 * post on every channel got a single generated picture whether or not that was
 * the right artefact — and the renderer we stood up to draw real panels sat
 * mostly idle.
 *
 * Three cases, and the omission is the important part.
 *
 * **There is no Reel.** Video is not deferred in the sense of "not styled yet";
 * nothing in this engine produces a frame of it. Offering the chip and greying
 * it out would be a promise the product cannot keep, and a menu that lies once
 * is not trusted again — the same rule the navigation column is held to.
 */
enum ContentFormat: string
{
    /**
     * Laid-out panels with real type, drawn by the renderer.
     *
     * The cheapest artefact we make and the one most under-used: a panel costs
     * compute rather than a generation, the text in it is perfect rather than
     * whatever an image model does to glyphs, and it is the right shape for
     * anything with steps, numbers or a comparison in it.
     */
    case Carousel = 'carousel';

    /** One generated photograph and the words beside it. */
    case Image = 'image';

    /**
     * Words alone.
     *
     * The shape has always supported this — `visual: 'none'` is in
     * {@see ContentIdea::plannedProduction()} — and nothing ever
     * produced it, so every post bought a picture whether the post wanted one
     * or not. An opinion or a question is frequently stronger without one, and
     * this is the only case here that makes a post *cheaper*.
     */
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::Carousel => 'Carousel',
            self::Image => 'Single image + text',
            self::Text => 'Text post',
        };
    }

    /**
     * What the picture step should do for this format.
     *
     * `slides` draws panels, `image` buys one photograph, `none` spends
     * nothing.
     */
    public function visual(): string
    {
        return match ($this) {
            self::Carousel => 'slides',
            self::Image => 'image',
            self::Text => 'none',
        };
    }

    /**
     * Whether this channel can carry the format.
     *
     * Only Instagram has a carousel; a Threads or X post asked for one falls
     * back to a single image rather than being refused, because the format is
     * an intent about the *idea* and an idea goes to more than one channel.
     * Text works everywhere.
     */
    public function isAvailableOn(ChannelType $channel): bool
    {
        return match ($this) {
            self::Carousel => $channel === ChannelType::Instagram,
            self::Image, self::Text => true,
        };
    }

    /** The format this channel actually produces when asked for this one. */
    public function on(ChannelType $channel): self
    {
        return $this->isAvailableOn($channel) ? $this : self::Image;
    }
}
