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
     *
     * **And Instagram is the one channel that cannot carry a text post**, for
     * the blunt reason that it will not accept a post without media. This used
     * to read "text works everywhere" and was harmless only because nothing
     * ever chose text: the moment the planner could, a `proof` idea marked text
     * would have shipped its Instagram half with no picture at all. The same
     * fallback applies in the other direction — the idea keeps its intent, and
     * the channel that cannot honour it gets one photograph.
     */
    public function isAvailableOn(ChannelType $channel): bool
    {
        return match ($this) {
            self::Carousel => $channel === ChannelType::Instagram,
            self::Text => $channel !== ChannelType::Instagram,
            self::Image => true,
        };
    }

    /** The format this channel actually produces when asked for this one. */
    public function on(ChannelType $channel): self
    {
        return $this->isAvailableOn($channel) ? $this : self::Image;
    }

    /**
     * Whether any of these channels produces this format as asked.
     *
     * @param  list<string>  $channels
     */
    public function honouredAnywhereIn(array $channels): bool
    {
        return self::split($this, $channels)['honoured'] !== [];
    }

    /**
     * What every format would really do to an idea on these channels.
     *
     * The Studio panel used to state what a format does in fixed copy — "Words
     * alone. Buys no picture at all." — and that sentence is true of some ideas
     * and a lie about others. A carousel is Instagram-only; a text post is
     * everywhere *but* Instagram, which will not publish without media. So on a
     * mixed-channel idea the honest answer is per channel, and on an
     * Instagram-only `offer` there is no text post to choose at all.
     *
     * Lives here, and is sent to the browser rather than recomputed there,
     * because the rule is {@see isAvailableOn()} and this codebase has now
     * found the same stale-second-copy failure four times: the kinds, the
     * picture rules, the evidence contract, the slide budgets.
     *
     * @param  list<string>  $channels
     * @return list<array{value: string, honoured: list<string>, falls_back: list<string>}>
     */
    public static function availability(array $channels): array
    {
        return array_map(
            static fn (self $format): array => [
                'value' => $format->value,
                ...self::split($format, $channels),
            ],
            self::cases(),
        );
    }

    /**
     * When to choose this, said to the party choosing it.
     *
     * Written as a property of the *idea* rather than of the kind, which is the
     * correction this method exists to make. {@see PostKind::instagramFormat()}
     * decides format from kind and argues that "only teaching is reliably a
     * sequence" — and the counterexample is a `behind` post about a published
     * checklist, which is a list of eight things and was published as a
     * photograph of cloths with the list in the caption. What decides a
     * carousel is whether this idea has parts, not which register it is in.
     */
    public function summary(): string
    {
        return match ($this) {
            self::Carousel => 'carousel — the idea has parts: steps, a comparison, a list, a sequence '
                .'of figures. Choose it when the argument arrives in order and loses something when '
                .'flattened into one picture. The panels are drawn, so words in them are legible and a '
                .'checklist or a set of numbers is a thing we can actually show. Instagram only.',
            self::Image => 'image — one photograph and the words beside it. Choose it when the idea is '
                .'one thing: a moment, a result, a place, a claim that a single frame can carry.',
            self::Text => 'text — no picture at all. Choose it when the words are the whole post and a '
                .'photograph would be decoration: an opinion, a question, a short argument. A picture '
                .'that illustrates nothing is worse than none.',
        };
    }

    /**
     * The formats as prompt lines, derived rather than typed.
     *
     * The kinds were typed out in three places and went stale in the third the
     * first time a case was added — a whole register reached the planner as a
     * token inside the mix arithmetic with nothing saying what it was. This is
     * the same list in the same prompt, so it is generated from the cases.
     *
     * @return list<string>
     */
    public static function vocabulary(): array
    {
        return array_map(static fn (self $format): string => '- '.$format->summary(), self::cases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }

    /** The formats a model may choose between, as prompt text. */
    public static function alternation(): string
    {
        return implode('|', self::values());
    }

    /**
     * What an unreadable or absent answer becomes.
     *
     * `image` rather than null, because null is not "no opinion" here — it is
     * the pre-existing fallback in {@see ContentIdea::format()}, which decides
     * from the kind and gives a carousel only to a how-to. A planner that
     * answered with nonsense should get the plain artefact, not the rule this
     * whole field exists to stop deciding for it.
     */
    public static function fallback(): self
    {
        return self::Image;
    }

    public static function tryFromLoose(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(mb_strtolower(trim($value)));
    }

    /**
     * What a kind means when nobody chose a format — the rule that used to be
     * the only rule.
     *
     * Kept so that an idea planned before the planner was asked for a format
     * behaves exactly as it did, and lives here rather than inline in
     * {@see ContentIdea::format()} because the mix check needs the same answer
     * about a proposed idea that has no row yet. Two copies of it is how the
     * kinds, the picture rules and the evidence contract each went stale in
     * their second place.
     */
    public static function impliedBy(PostKind $kind): self
    {
        return $kind->instagramFormat() === 'carousel' ? self::Carousel : self::Image;
    }

    /**
     * @param  list<string>  $channels
     * @return array{honoured: list<string>, falls_back: list<string>}
     */
    private static function split(self $format, array $channels): array
    {
        $honoured = [];
        $fallsBack = [];

        foreach ($channels as $channel) {
            $type = ChannelType::tryFrom($channel);

            if ($type === null) {
                continue;
            }

            if ($format->isAvailableOn($type)) {
                $honoured[] = $channel;
            } else {
                $fallsBack[] = $channel;
            }
        }

        return ['honoured' => $honoured, 'falls_back' => $fallsBack];
    }
}
