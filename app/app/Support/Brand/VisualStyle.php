<?php

declare(strict_types=1);

namespace App\Support\Brand;

use App\Models\BrandBrief;

/**
 * The brand's look as values something can draw with.
 *
 * The companion to `visual_language`, not a replacement for it. That field is
 * prose and goes to an image model, which is the right shape for instructing
 * one. This is for the renderers — the text overlay on a photograph, the
 * structured panel a teaching carousel is made of — and they need a fill
 * colour, an ink colour, a corner and a case rule. None of those can be read
 * out of "warm, unfussy, always a real workspace" without asking a model, and a
 * brand whose colour is decided by a model is a brand with a different colour
 * every Tuesday.
 *
 * **Everything is validated on the way out rather than trusted from the row.**
 * These columns are edited by a person in a form, and a half-typed hex value is
 * the normal state of a form field rather than an exceptional one. A renderer
 * handed `#12` produces either an exception in a queue worker or a black
 * rectangle, and both are worse than falling back to the default and carrying
 * on — a panel in the wrong colour is a thing an operator can see and fix.
 */
final readonly class VisualStyle
{
    /**
     * A near-black that is not black.
     *
     * Chosen because it is the safe default for the one job these values have:
     * carrying legible type. Pure black on a photograph reads as a missing
     * asset; this reads as a decision.
     */
    public const string DEFAULT_COLOUR = '#1a1a2e';

    public const string DEFAULT_INK = '#ffffff';

    /**
     * No accent, which resolves to the ink rather than to a colour.
     *
     * Empty on purpose, and the one default here that is not a value. A brand
     * that has not been asked for an accent has not got one, so the honest
     * fallback is the colour it already uses for emphasis — its ink — and not a
     * hue this engine picked. Every brief predating the field lands here, which
     * is why adding it changed no existing carousel.
     */
    public const string DEFAULT_ACCENT = '';

    public const string DEFAULT_POSITION = 'bottom';

    public const string DEFAULT_CASE = 'sentence';

    /** @var list<string> */
    public const array POSITIONS = ['top', 'centre', 'bottom'];

    /** @var list<string> */
    public const array CASES = ['sentence', 'upper'];

    /**
     * The floor for type this engine will draw.
     *
     * WCAG's large-text threshold. Every word on a panel is display size — the
     * smallest is a 34px counter — so the large-text rule is the applicable one
     * rather than a concession. Below this a heading does not fail gracefully:
     * it looks washed out on a good screen and disappears on a phone outdoors,
     * which is where these are read.
     */
    public const float MIN_CONTRAST = 3.0;

    private function __construct(
        public string $colour,
        public string $ink,
        /** Always a colour. Where the brief names none it is the ink. */
        public string $accent,
        public string $position,
        public string $case,
    ) {}

    public static function fromBrief(?BrandBrief $brief): self
    {
        if ($brief === null) {
            return self::fallback();
        }

        $ink = self::colour($brief->brand_ink, self::DEFAULT_INK);

        return new self(
            colour: self::colour($brief->brand_colour, self::DEFAULT_COLOUR),
            ink: $ink,
            // Resolved here rather than at each renderer, so nothing downstream
            // has to know that an unset accent means the ink — a rule that,
            // spread across three templates, is a rule three places can get
            // wrong. The ink is passed as the fallback rather than the default
            // white: a brand with a cream ink wants cream emphasis, not #ffffff.
            accent: self::colour($brief->brand_accent, $ink),
            position: in_array($brief->overlay_position, self::POSITIONS, true)
                ? $brief->overlay_position
                : self::DEFAULT_POSITION,
            case: in_array($brief->overlay_case, self::CASES, true)
                ? $brief->overlay_case
                : self::DEFAULT_CASE,
        );
    }

    public static function fallback(): self
    {
        return new self(
            colour: self::DEFAULT_COLOUR,
            ink: self::DEFAULT_INK,
            accent: self::DEFAULT_INK,
            position: self::DEFAULT_POSITION,
            case: self::DEFAULT_CASE,
        );
    }

    /**
     * Contrast between two colours, as WCAG counts it.
     *
     * Here rather than in a template because the templates are the wrong place
     * to decide it: a layout knows it is filling an area with the accent, and
     * has no idea whether this brand's accent can carry type. Seven templates
     * each guessing is seven chances to guess differently.
     */
    public function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channels = [];

            foreach ([0, 2, 4] as $offset) {
                $value = ((int) hexdec(substr($hex, $offset, 2))) / 255;
                $channels[] = $value <= 0.03928
                    ? $value / 12.92
                    : (($value + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $first = $luminance($a);
        $second = $luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    /**
     * Whichever of the brand's two type colours can be read on this background.
     *
     * For the layouts that fill an area with the accent — a `contrast`'s lower
     * half, the whole of a `cta`. The brand colour was the obvious choice and
     * the wrong one for this brand: forest on terracotta is 2.22:1, so the
     * slide asking for the follow was the least legible of the seven.
     */
    public function readableOn(string $background): string
    {
        return $this->contrast($this->ink, $background) >= $this->contrast($this->colour, $background)
            ? $this->ink
            : $this->colour;
    }

    /**
     * The accent where it can carry type, and the ink where it cannot.
     *
     * For the accent used *as* words — the figure on a `stat`. A brand whose
     * accent is too close to its fill still gets to keep that accent for rules,
     * ticks and fills, where contrast is not a legibility question; it simply
     * does not get a 300px number nobody can read. That is a fact about the
     * brand's palette rather than a fault, and the honest response is to draw
     * the legible thing and let the operator pick a lighter accent if they want
     * the emphasis back.
     */
    public function accentType(string $background): string
    {
        return $this->contrast($this->accent, $background) >= self::MIN_CONTRAST
            ? $this->accent
            : $this->readableOn($background);
    }

    /** This text as the brand writes it. */
    public function write(string $text): string
    {
        $text = trim($text);

        return $this->case === 'upper' ? mb_strtoupper($text) : $text;
    }

    /**
     * The colour as three 0–255 channels, which is what a renderer wants.
     *
     * @return array{int, int, int}
     */
    public function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'colour' => $this->colour,
            'ink' => $this->ink,
            'accent' => $this->accent,
            'position' => $this->position,
            'case' => $this->case,
        ];
    }

    /**
     * A six-digit hex colour, or the default.
     *
     * Three-digit shorthand is expanded rather than refused: `#fff` is what a
     * person types, and refusing it would be pedantry that costs the brand its
     * colour.
     */
    private static function colour(?string $value, string $fallback): string
    {
        $hex = ltrim(trim((string) $value), '#');

        if (preg_match('/^[0-9a-f]{3}$/i', $hex) === 1) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return preg_match('/^[0-9a-f]{6}$/i', $hex) === 1 ? '#'.strtolower($hex) : $fallback;
    }
}
