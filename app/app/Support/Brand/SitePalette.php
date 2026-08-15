<?php

declare(strict_types=1);

namespace App\Support\Brand;

use GdImage;

/**
 * The colours a site actually uses, read off a picture of it.
 *
 * **Arithmetic, not a model.** A screenshot's palette is a counting problem, and
 * counting is a thing computers are exactly right about. Asking a model would
 * cost a call, take a second, and — the part that matters — give a different
 * answer to the same site on different days, which is the failing the
 * {@see VisualStyle} docblock already objects to: "a brand whose colour is
 * decided by a model is a brand with a different colour every Tuesday."
 *
 * Three colours come out because three is what the renderers need: a fill, an
 * ink that reads on it, and an accent for the one thing on a panel that should
 * be looked at first.
 *
 * **Every one of them is a suggestion.** Nothing here writes to a Brand Brief.
 * A wrong fill is not a visible error — it silently becomes every carousel for a
 * month — so the operator confirms it in the form, the same way they now confirm
 * the assistant's goal rather than typing one into a blank field.
 */
final readonly class SitePalette
{
    /**
     * @param  string  $fill  the site's dominant coloured surface
     * @param  string  $ink  what reads on that fill, from the site's own light and dark
     * @param  string|null  $accent  its brightest secondary, where it has one
     */
    private function __construct(
        public string $fill,
        public string $ink,
        public ?string $accent,
    ) {}

    /**
     * Read a palette out of PNG bytes, or null where there is nothing to read.
     *
     * Null for an unreadable image and — deliberately — for a site that is
     * simply white with black text. That is most of the web, and proposing
     * `#ffffff` as a brand's fill is worse than proposing nothing: it looks like
     * an answer, and the operator who accepts it gets carousels the colour of a
     * blank page.
     */
    public static function fromPng(string $bytes): ?self
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        try {
            $counts = self::census($image);
        } finally {
            imagedestroy($image);
        }

        if ($counts === []) {
            return null;
        }

        return self::choose($counts);
    }

    /** @return array{fill: string, ink: string, accent: string|null} */
    public function toArray(): array
    {
        return ['fill' => $this->fill, 'ink' => $this->ink, 'accent' => $this->accent];
    }

    /**
     * How often each quantised colour appears, most common first.
     *
     * **Sampled, not scanned.** A 1280×900 capture is 1.15M pixels and every one
     * of them would be read to answer a question a grid of a few thousand
     * answers identically. The step is coprime-ish with the width so the samples
     * do not land in a column and miss a sidebar.
     *
     * Quantised to 4 bits a channel. A gradient, a JPEG artefact and a
     * subpixel-antialiased edge are all the same colour to a person and three
     * thousand different colours to a histogram; without this the most common
     * "colour" on any real site is a rounding error at the edge of a letter.
     *
     * @return array<string, int>
     */
    private static function census(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(min($width, $height) / 60));
        $counts = [];

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $rgb = imagecolorat($image, $x, $y);

                // Kept with its hash, and that is not cosmetic: PHP casts a
                // numeric-string array key to an integer, so a perfectly
                // ordinary colour like "123456" would come back out of this
                // histogram as an int and every string function downstream
                // would receive the wrong type.
                $key = sprintf(
                    '#%02x%02x%02x',
                    (($rgb >> 16) & 0xFF) & 0xF0,
                    (($rgb >> 8) & 0xFF) & 0xF0,
                    ($rgb & 0xFF) & 0xF0,
                );

                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Pick the three from the census.
     *
     * The fill is the most common colour that is *not* a neutral, because a
     * site's most common colour is nearly always its page background and that is
     * nearly always white. What a brand means by "our colour" is the one it
     * paints a header or a footer with.
     *
     * The ink is whichever of the site's own near-white and near-black reads on
     * that fill, rather than a computed #fff — a brand whose light neutral is a
     * warm cream should keep its cream.
     *
     * The accent is the next chromatic colour far enough from the fill in hue to
     * be a different decision rather than a shade of it, and null where there is
     * no such thing. Most sites have two colours and inventing a third is the
     * exact move this whole feature exists to avoid.
     *
     * @param  array<string, int>  $counts
     */
    private static function choose(array $counts): ?self
    {
        $chromatic = [];
        $lightest = null;
        $darkest = null;

        foreach ($counts as $hex => $count) {
            $hex = (string) $hex;
            [$h, $chroma, $l] = self::hsl($hex);

            // Chroma, not HSL saturation, and the difference decides whether
            // this works at all. Saturation is a ratio against how much room
            // there is left to the edge of the cube, so it climbs towards 1 as
            // a colour approaches white: a warm cream page scores 0.35 and a
            // deep forest green scores 0.33, which makes them indistinguishable
            // to any threshold. Chroma is the plain spread between the
            // channels — 0.06 for the cream, 0.13 for the forest — and it says
            // what a person means by "is that a colour or a shade of paper".
            if ($chroma < 0.10 || $l > 0.93 || $l < 0.06) {
                // Neutrals are not brand colours, but the palest and the
                // deepest of them are the brand's paper and its ink.
                if ($lightest === null || $l > $lightest[1]) {
                    $lightest = [$hex, $l];
                }

                if ($darkest === null || $l < $darkest[1]) {
                    $darkest = [$hex, $l];
                }

                continue;
            }

            $chromatic[] = ['hex' => $hex, 'count' => $count, 'h' => $h, 'chroma' => $chroma, 'l' => $l];
        }

        if ($chromatic === []) {
            return null;
        }

        $fill = $chromatic[0];
        $light = $lightest[0] ?? '#ffffff';
        $dark = $darkest[0] ?? '#111111';

        $style = VisualStyle::fallback();
        $ink = $style->contrast($light, $fill['hex']) >= $style->contrast($dark, $fill['hex'])
            ? $light
            : $dark;

        $accent = null;

        foreach (array_slice($chromatic, 1) as $candidate) {
            // 40 degrees apart, which is about where two colours stop reading as
            // the same one at different brightness. A "second colour" that is
            // the first one lightened gives an accent nobody can see.
            $apart = abs($candidate['h'] - $fill['h']);
            $apart = min($apart, 360 - $apart);

            if ($apart >= 40) {
                $accent = $candidate['hex'];

                break;
            }
        }

        return new self(fill: $fill['hex'], ink: $ink, accent: $accent);
    }

    /**
     * @return array{float, float, float} hue in degrees, chroma and lightness 0–1
     */
    private static function hsl(string $hex): array
    {
        $hex = ltrim($hex, '#');

        $r = ((int) hexdec(substr($hex, 0, 2))) / 255;
        $g = ((int) hexdec(substr($hex, 2, 2))) / 255;
        $b = ((int) hexdec(substr($hex, 4, 2))) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $delta = $max - $min;

        if ($delta === 0.0) {
            return [0.0, 0.0, $l];
        }

        $h = match (true) {
            $max === $r => fmod(($g - $b) / $delta, 6),
            $max === $g => (($b - $r) / $delta) + 2,
            default => (($r - $g) / $delta) + 4,
        } * 60;

        return [$h < 0 ? $h + 360 : $h, $delta, $l];
    }
}
