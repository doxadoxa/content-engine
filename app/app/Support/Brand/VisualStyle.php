<?php

declare(strict_types=1);

namespace App\Support\Brand;

use App\Models\BrandBrief;

/**
 * The brand's look as exact values rather than prose.
 *
 * The companion to `visual_language`, not a replacement for it. That field is
 * prose and goes to an image model, which is the right shape for instructing
 * one. This holds what prose cannot pin down: a fill colour, an ink colour, an
 * accent, a typeface, a position and a case rule. None of those can be read
 * out of "warm, unfussy, always a real workspace" without asking a model, and
 * a brand whose colour is decided by a model is a brand with a different
 * colour every Tuesday.
 *
 * Nothing draws with these values today. The brief form edits them, and
 * {@see SitePalette} uses the contrast arithmetic here to suggest a starting
 * set from the project's own site.
 *
 * **Everything is validated on the way out rather than trusted from the row.**
 * These columns are edited by a person in a form, and a half-typed hex value is
 * the normal state of a form field rather than an exceptional one. Whatever
 * reads `#12` from here gets the default instead, which is a value it can
 * work with rather than an exception.
 */
final readonly class VisualStyle
{
    /**
     * A near-black that is not black.
     *
     * Chosen because it is the safe default for a colour that has to carry
     * legible white type. Pure black reads as a missing value; this reads as a
     * decision.
     */
    public const string DEFAULT_COLOUR = '#1a1a2e';

    public const string DEFAULT_INK = '#ffffff';

    /**
     * No accent, which resolves to the ink rather than to a colour.
     *
     * Empty on purpose, and the one default here that is not a value. A brand
     * that has not been asked for an accent has not got one, so the honest
     * fallback is the colour it already uses for emphasis — its ink — and not a
     * hue this engine picked. Every brief predating the field lands here.
     */
    public const string DEFAULT_ACCENT = '';

    public const string DEFAULT_POSITION = 'bottom';

    public const string DEFAULT_CASE = 'sentence';

    /** @var list<string> */
    public const array POSITIONS = ['top', 'centre', 'bottom'];

    /** @var list<string> */
    public const array CASES = ['sentence', 'upper'];

    public const string DEFAULT_TYPEFACE = 'instrument-sans';

    /**
     * The faces a brand can be set in, as slug => family name.
     *
     * **A list of what is bundled, not a style menu.** The reference this came
     * from offers five faces as a look to choose — right for a design tool where
     * a person is picking an aesthetic, and wrong here for the same reason
     * {@see SitePalette} does not offer a palette to choose: the brief records
     * the brand's own values rather than ones this engine picked. So the honest axis is "which of these is yours", and the site read
     * answers it — `site_analysis.brand_font` comes back as the family the
     * stylesheet actually sets.
     *
     * Each entry has its woff2 files under `resources/fonts/<slug>/`, and
     * adding one is dropping those files in and a line here.
     *
     * @var array<string, string>
     */
    public const array TYPEFACES = [
        'instrument-sans' => 'Instrument Sans',
        'poppins' => 'Poppins',
    ];

    /**
     * The contrast floor for a colour that has to carry type.
     *
     * WCAG's large-text threshold. Below this a heading does not fail
     * gracefully: it looks washed out on a good screen and disappears on a
     * phone outdoors.
     */
    public const float MIN_CONTRAST = 3.0;

    private function __construct(
        public string $colour,
        public string $ink,
        /** Always a colour. Where the brief names none it is the ink. */
        public string $accent,
        public string $position,
        public string $case,
        /** The slug of the brand's face. Always one of {@see TYPEFACES}. */
        public string $typeface = self::DEFAULT_TYPEFACE,
        /**
         * The brand's other colours, heaviest first, and usually empty.
         *
         * Never answered directly. This is what {@see accentType()} and
         * {@see readableOn()} reach into *after* their own answer has failed
         * {@see MIN_CONTRAST} — the difference between a brand that has more
         * colours and a brand that gets to use them. A brief that names none
         * behaves exactly as it did before the list existed.
         *
         * @var list<string>
         */
        public array $palette = [],
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
            // Resolved here rather than by each reader, so nothing downstream
            // has to know that an unset accent means the ink — a rule that,
            // spread across several readers, is a rule several places can get
            // wrong. The ink is passed as the fallback rather than the default
            // white: a brand with a cream ink wants cream emphasis, not #ffffff.
            accent: self::colour($brief->brand_accent, $ink),
            position: in_array($brief->overlay_position, self::POSITIONS, true)
                ? $brief->overlay_position
                : self::DEFAULT_POSITION,
            case: in_array($brief->overlay_case, self::CASES, true)
                ? $brief->overlay_case
                : self::DEFAULT_CASE,
            typeface: array_key_exists((string) $brief->brand_typeface, self::TYPEFACES)
                ? (string) $brief->brand_typeface
                : self::DEFAULT_TYPEFACE,
            palette: self::palette($brief),
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
     * Here rather than with each caller, because a caller knows which two
     * colours it is putting together and has no idea whether this brand's
     * accent can carry type. {@see SitePalette} uses it to keep a suggested
     * accent apart from the fill.
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
     * For type set on an area filled with the accent. The brand colour is the
     * obvious choice and can be the wrong one: forest on terracotta is 2.22:1.
     *
     * **And where neither of the two can be read, the rest of the brand is
     * asked.** Only then: a brand whose ink or colour already clears
     * {@see MIN_CONTRAST} takes precisely the path it took before the palette
     * existed. Highest contrast wins here rather than first — this is the
     * legibility question, and the most readable answer is the right one.
     */
    public function readableOn(string $background): string
    {
        $best = $this->contrast($this->ink, $background) >= $this->contrast($this->colour, $background)
            ? $this->ink
            : $this->colour;

        if ($this->contrast($best, $background) >= self::MIN_CONTRAST) {
            return $best;
        }

        foreach ($this->palette as $candidate) {
            if ($this->contrast($candidate, $background) > $this->contrast($best, $background)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * The accent where it can carry type, and the ink where it cannot.
     *
     * For the accent used *as* words. A brand whose accent is too close to its
     * fill still keeps that accent for rules, ticks and fills, where contrast
     * is not a legibility question; it simply does not get type nobody can
     * read.
     *
     * **The palette is the "lighter accent" this used to ask an operator for.**
     * Now that a brand's other colours are on the brief, an accent that cannot
     * carry type is followed by a look through them before giving up on colour
     * altogether, and only falls back to the ink when the brand genuinely has
     * nothing that reads.
     *
     * First that clears the floor, not highest contrast, and the difference
     * matters: the list is ordered by weight on the page, so the first is the
     * most prominent colour that works. Sorting by contrast would reliably pick
     * whatever is nearest black or white, which is the ink by another name and
     * loses exactly the emphasis this exists to keep.
     */
    public function accentType(string $background): string
    {
        if ($this->contrast($this->accent, $background) >= self::MIN_CONTRAST) {
            return $this->accent;
        }

        foreach ($this->palette as $candidate) {
            if ($candidate === $this->accent || $candidate === $background) {
                continue;
            }

            if ($this->contrast($candidate, $background) >= self::MIN_CONTRAST) {
                return $candidate;
            }
        }

        return $this->readableOn($background);
    }

    /** This text as the brand writes it. */
    public function write(string $text): string
    {
        $text = trim($text);

        return $this->case === 'upper' ? mb_strtoupper($text) : $text;
    }

    /**
     * The colour as three 0–255 channels.
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
     * The stored palette, cleaned the same way every other colour here is.
     *
     * A column an operator can edit and a seeder can write, so it is read the
     * way the rest of this class reads colours: anything that is not a hex is
     * dropped rather than repaired, because a repaired value in this list would
     * be a colour nobody chose.
     *
     * @return list<string>
     */
    private static function palette(BrandBrief $brief): array
    {
        $clean = [];

        foreach ($brief->brand_palette as $entry) {
            $hex = self::colour($entry, '');

            if ($hex !== '' && ! in_array($hex, $clean, true)) {
                $clean[] = $hex;
            }
        }

        return $clean;
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
