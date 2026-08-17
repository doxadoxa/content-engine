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
    /** Spread below which a sample counts as flat colour rather than picture. */
    private const float FLAT = 0.06;

    /**
     * How much of a colour must be flat before it counts as a painted surface.
     *
     * Half, and the gap it sits in is wide enough that the exact figure barely
     * matters: measured across real pages, a brand's own surface scores above
     * 0.8 and everything inside a photograph scores below 0.1.
     */
    private const float SURFACE = 0.5;

    /** Below this a colour is an anti-aliasing artefact, not a decision. */
    private const int MIN_SAMPLES = 8;

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
     * How much of the page each quantised colour is *worth*, most first.
     *
     * **Sampled, not scanned.** A 1280×900 capture is 1.15M pixels and every one
     * of them would be read to answer a question a grid of a few thousand
     * answers identically.
     *
     * Quantised to 4 bits a channel. A gradient, a JPEG artefact and a
     * subpixel-antialiased edge are all the same colour to a person and three
     * thousand different colours to a histogram; without this the most common
     * "colour" on any real site is a rounding error at the edge of a letter.
     *
     * **Weighted, not counted, and that is what makes it usable.** Plain area
     * picked whatever the biggest photograph happened to be: Cleaning Point's
     * own site returned a beige — the sand in its hero image — for a brand whose
     * identity is forest green and terracotta, and a suggestion that is usually
     * wrong is one people stop reading. Two corrections, and each answers a
     * different half of that:
     *
     * - **where** the colour is. A brand paints its header and its footer; the
     *   middle of the page is where photographs and body copy live.
     * - **whether it is flat.** Design is flat colour and photography is not, so
     *   a sample surrounded by variation is almost certainly a picture and a
     *   sample surrounded by its own colour is almost certainly a surface.
     *
     * Neither is a threshold that includes or excludes. They multiply into a
     * weight, so a large enough photograph can still win if a site genuinely has
     * no coloured furniture — which is the honest answer for a site that is a
     * full-bleed image and nothing else.
     *
     * @return array<string, array{weight: float, samples: int, flat: int}>
     */
    private static function census(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(min($width, $height) / 60));
        $counts = [];

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $spread = self::spread($image, $x, $y, $step * 3, $width, $height);
                $weight = self::whereWeight($y, $height) * self::flatWeight($spread);
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

                $counts[$key]['weight'] = ($counts[$key]['weight'] ?? 0.0) + $weight;
                $counts[$key]['samples'] = ($counts[$key]['samples'] ?? 0) + 1;
                $counts[$key]['flat'] = ($counts[$key]['flat'] ?? 0) + ($spread <= self::FLAT ? 1 : 0);
            }
        }

        uasort($counts, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return $counts;
    }

    /**
     * What a sample is worth for being where it is.
     *
     * The header carries the most, because a navigation bar is the one strip of
     * a page a brand almost always paints in its own colour. The footer carries
     * more than the middle for the same reason and less than the header, because
     * a first-fold capture often does not reach it and what it does reach is as
     * likely to be a newsletter block.
     *
     * Bands rather than a smooth curve: the boundary between "furniture" and
     * "content" is a real edge on a real page, not a gradient, and a curve would
     * be three constants pretending to be a principle.
     */
    private static function whereWeight(int $y, int $height): float
    {
        $position = $height <= 0 ? 0.0 : $y / $height;

        return match (true) {
            $position < 0.15 => 2.5,
            $position > 0.85 => 1.5,
            default => 1.0,
        };
    }

    /**
     * How much the colour varies around a sample, 0 to 1.
     *
     * **Probed wide — three sampling steps, not half of one.** Photography and
     * design are both locally smooth, so a tight probe cannot tell a painted
     * band from the wall behind somebody's head; measured against Cleaning
     * Point's own homepage the two were 0.097 and 0.084, which is no signal at
     * all. Widen it and they separate: a painted band is still flat at forty-five
     * pixels and a photograph is not.
     */
    private static function spread(
        GdImage $image,
        int $x,
        int $y,
        int $reach,
        int $width,
        int $height,
    ): float {
        $reach = max(1, $reach);

        $probes = [
            [$x, $y],
            [max(0, $x - $reach), $y],
            [min($width - 1, $x + $reach), $y],
            [$x, max(0, $y - $reach)],
            [$x, min($height - 1, $y + $reach)],
        ];

        $low = [255, 255, 255];
        $high = [0, 0, 0];

        foreach ($probes as [$px, $py]) {
            $rgb = imagecolorat($image, $px, $py);

            foreach ([16, 8, 0] as $index => $shift) {
                $channel = ($rgb >> $shift) & 0xFF;
                $low[$index] = min($low[$index], $channel);
                $high[$index] = max($high[$index], $channel);
            }
        }

        return max(
            $high[0] - $low[0],
            $high[1] - $low[1],
            $high[2] - $low[2],
        ) / 255;
    }

    /**
     * What a sample is worth for sitting in flat colour rather than in a picture.
     *
     * **Tapered, not switched.** A hard cutoff here would throw away the flat
     * regions inside a photograph — a clear sky, a studio backdrop — and those
     * are exactly the parts that look like a brand colour. Fading the weight lets
     * a large flat photographic area lose to a smaller painted one rather than be
     * excluded on a technicality that moves with the threshold.
     *
     * The floor is not zero: a photograph is still something the page is made of,
     * and the decision about whether a colour is a *surface* is taken separately
     * and absolutely — see {@see isSurface()}. This only ranks.
     */
    private static function flatWeight(float $spread): float
    {
        return match (true) {
            $spread <= self::FLAT => 1.0,
            $spread >= 0.30 => 0.15,
            default => 1.0 - (($spread - self::FLAT) / (0.30 - self::FLAT)) * 0.85,
        };
    }

    /**
     * Whether a colour is a surface the page is painted with, or just present in it.
     *
     * **The question weighting could not answer.** Ranking by area, however it is
     * weighted, asks "which colour is there most of" — and on a white page with
     * a large photograph and a small teal button, the honest answer to that is
     * the photograph. Measured on Cleaning Point: the beige of its hero image
     * scored 117 samples against the brand teal's 78, and no combination of
     * position and flatness weights changes that ordering, because the beige
     * genuinely does cover more of the page.
     *
     * This asks a different question — is this colour *flat wherever it appears*
     * — and the two sites separate completely on it. GitHub's navy is flat across
     * 83% of its samples; every chromatic colour on Cleaning Point's page is flat
     * across 7% or fewer, because each is either inside a photograph or a button
     * the size of a thumbnail.
     *
     * So a site with no painted surface gets no suggestion, which is the right
     * answer rather than a shortfall: proposing the sand in somebody's hero image
     * as their brand colour is worse than proposing nothing, for the reason this
     * whole class exists to respect.
     *
     * The sample floor is what keeps a single anti-aliased pixel out. Without it
     * `example.com` — a page with no colour on it whatsoever — returned a
     * blue-grey it had exactly one sample of.
     *
     * @param  array{weight: float, samples: int, flat: int}  $candidate
     */
    private static function isSurface(array $candidate): bool
    {
        return $candidate['samples'] >= self::MIN_SAMPLES
            && $candidate['flat'] / $candidate['samples'] >= self::SURFACE;
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
     * @param  array<string, array{weight: float, samples: int, flat: int}>  $counts
     */
    private static function choose(array $counts): ?self
    {
        $chromatic = [];
        $lightest = null;
        $darkest = null;

        foreach ($counts as $hex => $candidate) {
            $hex = (string) $hex;
            $weight = $candidate['weight'];
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

            $chromatic[] = [
                'hex' => $hex,
                'weight' => $weight,
                'h' => $h,
                'surface' => self::isSurface($candidate),
            ];
        }

        if ($chromatic === []) {
            return null;
        }

        // The heaviest colour that is actually a painted surface. Not simply the
        // heaviest: on a photo-led page that is the photograph, and a fill is
        // the thing a panel is filled *with*.
        $fill = null;

        foreach ($chromatic as $candidate) {
            if ($candidate['surface']) {
                $fill = $candidate;

                break;
            }
        }

        // Nothing on this page is painted, so there is nothing to suggest. A
        // site whose colour lives only in a button has a brand colour; it does
        // not have one this can count, and guessing is what made the first
        // version of this unusable.
        if ($fill === null) {
            return null;
        }
        $light = $lightest[0] ?? '#ffffff';
        $dark = $darkest[0] ?? '#111111';

        $style = VisualStyle::fallback();
        $ink = $style->contrast($light, $fill['hex']) >= $style->contrast($dark, $fill['hex'])
            ? $light
            : $dark;

        $accent = null;

        foreach ($chromatic as $candidate) {
            if ($candidate['hex'] === $fill['hex']) {
                continue;
            }

            // Deliberately not required to be a surface. A fill is something a
            // page is painted with and an accent is a mark made on it — a
            // button, a rule, a tag — so demanding acreage of it would throw
            // away exactly the small vivid colours an accent is for.
            //
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

        // Cast to float *before* dividing, and it is not decoration. PHP's `/`
        // returns an int when both operands are ints and the division is exact,
        // so `0x00 / 255` is `int(0)` and `0xff / 255` is `int(1)` — while every
        // other channel value comes back a float. On a pure black or pure white
        // pixel the delta below was therefore `int(0)`, the strict `=== 0.0`
        // guard did not match it, and the achromatic case fell through to a
        // division by that same zero. It never fired in tests because the
        // 4-bit quantisation turns 255 into 240, so only genuine black reaches
        // it — which every real screenshot has and no generated fixture did.
        $r = ((float) hexdec(substr($hex, 0, 2))) / 255;
        $g = ((float) hexdec(substr($hex, 2, 2))) / 255;
        $b = ((float) hexdec(substr($hex, 4, 2))) / 255;

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
