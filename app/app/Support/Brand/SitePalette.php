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
     * Where "a colour" starts, and where paper and ink take over.
     *
     * Named because {@see fromDeclared()} draws the same three lines the census
     * does and they have to move together — a stylesheet and a photograph
     * disagreeing about whether a cream is a colour would put two different
     * palettes behind one button. Chroma rather than saturation for the reason
     * {@see choose()} sets out at length: saturation climbs towards 1 as a
     * colour approaches white, which makes a warm cream and a deep forest green
     * indistinguishable to any threshold.
     */
    private const float CHROMATIC = 0.10;

    /** Above this a colour is the page's paper rather than one of its colours. */
    private const float PALEST = 0.93;

    /** And below it, the page's ink. */
    private const float DEEPEST = 0.06;

    /**
     * How much colour an accent needs to be a mark rather than a tint.
     *
     * Higher than {@see CHROMATIC}, and deliberately: a fill has area to prove
     * itself with and an accent has almost none, so the pale wash a page puts
     * behind a callout would otherwise qualify on being faintly tinted. Cleaning
     * Point declares `#ddf7f5` at 0.10 and its teal at 0.66; this sits where
     * only the second reads as a colour somebody chose.
     */
    private const float ACCENT_CHROMA = 0.25;

    /**
     * And how far it has to stand off the fill it is drawn on.
     *
     * WCAG's floor for large text and graphical objects, which is what an accent
     * is — a figure on a statistic, a rule, a tag. Below this the emphasis is
     * there in the markup and invisible on the panel.
     */
    private const float ACCENT_CONTRAST = 3.0;

    /**
     * How saturated a colour must be to stand in for a surface that is not there.
     *
     * Only ever consulted on a page that paints nothing — see {@see choose()}.
     * Measured on Cleaning Point's page, whose entire colour is a teal button:
     * the sand of the hero photograph reads 0.125, its navy type 0.314, and the
     * teal 0.627.
     *
     * Chroma rather than weight, because weight is exactly what {@see SURFACE}
     * was invented to beat — the sand outweighs the teal three to one and is
     * still a photograph. Flatness cannot separate them here either (0.068
     * against 0.000, both nothing); chroma separates them five to one.
     *
     * The figure is set by the *other* end, though, and that is the one worth
     * keeping honest. A photograph is not uniformly muted — it has highlights —
     * and the noise the suite photographs a page with reaches 0.478 at its
     * extreme, being a warm beige varied ±45 per channel. So this sits halfway
     * between that and the teal: high enough that a photograph's own brightest
     * tone does not qualify, low enough that a brand's one vivid mark does.
     * Both margins are the same size, which is the most that can be claimed for
     * it — a real photograph of something genuinely saturated would clear this,
     * and the answer to that page is a person looking at the suggestion.
     */
    private const float VIVID = 0.55;

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

    /**
     * The palette from what the page *says*, rather than from how it came out.
     *
     * Preferred over {@see fromPng()} wherever the stylesheet could be read, and
     * the reason is that a photograph is a lossy record of a decision somebody
     * already wrote down. The census quantises to the nearest sixteenth per
     * channel and cannot see anything smaller than its sampling grid; the
     * cascade has the exact value and has it whether the colour paints a hero or
     * one hover state. Measured on Cleaning Point: the teal is `#22cbc5`
     * declared and `#20c0c0` photographed, the navy `#002954` against `#002050`,
     * and its second teal — which only ever appears as text — is absent from the
     * census at every threshold.
     *
     * The three are chosen on different tests, because they are different jobs:
     *
     * - **fill** is the heaviest chromatic *background*. Still backgrounds only,
     *   and still by area, because a fill is the thing a panel is painted with
     *   and a page's own answer to that is what it paints itself with.
     * - **ink** comes from the site's own neutrals, exactly as before.
     * - **accent** is the heaviest colour vivid enough to be a decision that
     *   also stands apart from the fill. Contrast rather than the hue distance
     *   the census path uses, because hue alone gets this wrong on real
     *   stylesheets: Cleaning Point's navy and teal are 33° apart and would fail
     *   a 40° test, while being about as visually distinct as two colours get.
     *   What that test was reaching for was "an accent nobody can see", and
     *   contrast measures that directly.
     *
     * @param  list<array{hex: string, role: string, weight: int}>  $colours
     */
    public static function fromDeclared(array $colours): ?self
    {
        $fill = null;

        foreach ($colours as $colour) {
            if ($colour['role'] !== 'background') {
                continue;
            }

            [, $chroma, $l] = self::hsl($colour['hex']);

            if ($chroma < self::CHROMATIC || $l > self::PALEST || $l < self::DEEPEST) {
                continue;
            }

            if ($fill === null || $colour['weight'] > $fill['weight']) {
                $fill = $colour;
            }
        }

        // Nothing on this page paints a colour of its own, which is a fact about
        // the page and not a shortfall in the reading. The caller falls back to
        // the census, which has its own vividness rule for exactly this shape.
        if ($fill === null) {
            return null;
        }

        $style = VisualStyle::fallback();
        $ink = self::inkFor($fill['hex'], $colours, $style);

        $accent = null;
        $accentWeight = -1;

        foreach ($colours as $colour) {
            if ($colour['hex'] === $fill['hex'] || $colour['weight'] <= $accentWeight) {
                continue;
            }

            [, $chroma] = self::hsl($colour['hex']);

            // Vivid, because a pale tint of the page is not an accent however
            // much of it there is, and `CHROMATIC` is low enough to admit one.
            if ($chroma < self::ACCENT_CHROMA) {
                continue;
            }

            // And separate from the fill it will be drawn on. This is what stops
            // a lighter shade of the brand colour becoming its own accent —
            // vivid enough to pass the test above, invisible against the panel.
            if ($style->contrast($colour['hex'], $fill['hex']) < self::ACCENT_CONTRAST) {
                continue;
            }

            $accent = $colour['hex'];
            $accentWeight = $colour['weight'];
        }

        return new self(fill: $fill['hex'], ink: $ink, accent: $accent);
    }

    /** @return array{fill: string, ink: string, accent: string|null} */
    public function toArray(): array
    {
        return ['fill' => $this->fill, 'ink' => $this->ink, 'accent' => $this->accent];
    }

    /**
     * Whichever of the site's own neutrals reads on the fill.
     *
     * The site's rather than a computed black or white, for the reason the
     * census path gives: a brand whose light neutral is a warm cream should keep
     * its cream. A page that declares no neutral at all falls back to the pair
     * every screen has.
     *
     * @param  list<array{hex: string, role: string, weight: int}>  $colours
     */
    private static function inkFor(string $fill, array $colours, VisualStyle $style): string
    {
        $lightest = null;
        $darkest = null;

        foreach ($colours as $colour) {
            [, $chroma, $l] = self::hsl($colour['hex']);

            // The same division the census makes: a neutral is anything without
            // enough colour in it to be a decision, plus the palest and deepest
            // of everything, which are a page's paper and its ink.
            if ($chroma >= self::CHROMATIC && $l <= self::PALEST && $l >= self::DEEPEST) {
                continue;
            }

            if ($lightest === null || $l > $lightest[1]) {
                $lightest = [$colour['hex'], $l];
            }

            if ($darkest === null || $l < $darkest[1]) {
                $darkest = [$colour['hex'], $l];
            }
        }

        $light = $lightest[0] ?? '#ffffff';
        $dark = $darkest[0] ?? '#111111';

        return $style->contrast($light, $fill) >= $style->contrast($dark, $fill)
            ? $light
            : $dark;
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
            if ($chroma < self::CHROMATIC || $l > self::PALEST || $l < self::DEEPEST) {
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
                'chroma' => $chroma,
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

        // Whether this page paints anything at all, kept because the accent
        // rule below depends on it. A page with a surface has earned the benefit
        // of the doubt about its smaller colours; a page without one has not.
        $painted = $fill !== null;

        // Nothing on this page is painted. A site whose colour lives only in a
        // button still has a brand colour, and this used to throw it away: the
        // acreage rule that the accent is explicitly exempt from was in practice
        // applied to the whole palette, because the exemption sits forty lines
        // below this return and never ran on the one page shape it was written
        // for.
        //
        // So the most saturated colour stands in, rather than the heaviest.
        // See {@see VIVID} for the measurements, and for why heaviest is the
        // wrong question on precisely the pages that reach this line.
        if ($fill === null) {
            foreach ($chromatic as $candidate) {
                if ($candidate['chroma'] < self::VIVID) {
                    continue;
                }

                if ($fill === null || $candidate['chroma'] > $fill['chroma']) {
                    $fill = $candidate;
                }
            }
        }

        // Still nothing, which on a page that paints no surface means every
        // colour on it is muted — a photograph, and nothing that was chosen.
        // Suggesting the best of those is the guess that made the first version
        // of this unusable, so it stays unsuggested.
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
            // Required to be vivid, though, and only where the page paints
            // nothing. A page with a surface has shown it makes colour
            // decisions, so its smaller colours get the benefit of the doubt.
            // A page without one has shown the opposite: every colour on it
            // that is not the one vivid mark came out of a photograph, and on
            // Cleaning Point's page the best-weighted of those is the sand of
            // the hero at chroma 0.125. That is a suggestion the operator would
            // read as an error, which is the failing this whole class is about.
            if (! $painted && $candidate['chroma'] < self::VIVID) {
                continue;
            }

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
