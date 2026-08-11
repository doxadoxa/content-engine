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

    public const string DEFAULT_POSITION = 'bottom';

    public const string DEFAULT_CASE = 'sentence';

    /** @var list<string> */
    public const array POSITIONS = ['top', 'centre', 'bottom'];

    /** @var list<string> */
    public const array CASES = ['sentence', 'upper'];

    private function __construct(
        public string $colour,
        public string $ink,
        public string $position,
        public string $case,
    ) {}

    public static function fromBrief(?BrandBrief $brief): self
    {
        if ($brief === null) {
            return self::fallback();
        }

        return new self(
            colour: self::colour($brief->brand_colour, self::DEFAULT_COLOUR),
            ink: self::colour($brief->brand_ink, self::DEFAULT_INK),
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
            position: self::DEFAULT_POSITION,
            case: self::DEFAULT_CASE,
        );
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
