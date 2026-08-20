<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Support\Brand\SitePalette;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Reading a brand's colours off a picture of its site.
 *
 * Built against generated images rather than fixtures of real sites, and that is
 * not a shortcut: a fixture proves the answer for one site on one day, while a
 * generated page states the intent — "a white page with a coloured header and a
 * differently-coloured button" — and asserts the reading against it. When the
 * heuristic is wrong, a failure here says which rule was wrong rather than which
 * screenshot moved.
 */
final class SitePaletteTest extends TestCase
{
    /**
     * The page's own values, exactly, and the right three of them.
     *
     * The census reading of this same page answers `#20c0c0` and `#002050` —
     * both quantised to the nearest sixteenth, neither a colour the brand has
     * ever written down.
     */
    #[Test]
    public function it_reads_the_declared_colours_rather_than_the_nearest_sixteenth(): void
    {
        $palette = SitePalette::fromDeclared($this->cleaningPoint());

        $this->assertNotNull($palette);

        // The navy, because a fill is what a page paints with and this is the
        // heaviest thing it paints in a colour of its own.
        $this->assertSame('#002954', $palette->fill);

        // White, from the page's own neutrals, because it reads on the navy.
        $this->assertSame('#ffffff', $palette->ink);

        // And the teal, which is 33° from the navy in hue and would have been
        // refused by the census path's 40° test while being about as visibly
        // different from it as two colours get.
        $this->assertSame('#22cbc5', $palette->accent);
    }

    /**
     * A pale tint is not an accent, however much of the page is covered in it.
     *
     * `#ddf7f5` outweighs the teal two hundred to one here and is the wash
     * behind a callout. Weight is the wrong question for an accent — a mark is
     * small by definition — so the test is vividness, and this is why it sits
     * higher than the bar a fill has to clear.
     */
    #[Test]
    public function a_pale_wash_is_not_offered_as_the_accent(): void
    {
        $palette = SitePalette::fromDeclared($this->cleaningPoint());

        $this->assertNotNull($palette);
        $this->assertNotSame('#ddf7f5', $palette->accent);
    }

    /**
     * And a lighter shade of the fill is not one either.
     *
     * The failure the census path spends forty degrees of hue on, caught here by
     * asking the question it was actually reaching for: an accent that does not
     * stand off the panel it is drawn on is an accent nobody can see.
     */
    #[Test]
    public function a_lighter_shade_of_the_fill_is_not_the_accent(): void
    {
        $palette = SitePalette::fromDeclared([
            ['hex' => '#22cbc5', 'role' => 'background', 'weight' => 900000],
            // Vivid enough to pass on colour, far too close to pass on contrast.
            ['hex' => '#4dd7d1', 'role' => 'background', 'weight' => 8000],
            ['hex' => '#ffffff', 'role' => 'text', 'weight' => 40],
        ]);

        $this->assertNotNull($palette);
        $this->assertSame('#22cbc5', $palette->fill);
        $this->assertNull($palette->accent);
    }

    /**
     * A page that declares no colour of its own hands back to the census.
     *
     * Null rather than a guess, because the caller's next move is
     * {@see SitePalette::fromPng()} — a site whose colour lives entirely in
     * imagery declares nothing useful and is exactly what the picture is for.
     */
    #[Test]
    public function a_stylesheet_with_no_painted_colour_defers_to_the_picture(): void
    {
        $this->assertNull(SitePalette::fromDeclared([
            ['hex' => '#ffffff', 'role' => 'background', 'weight' => 900000],
            ['hex' => '#fafafa', 'role' => 'background', 'weight' => 40000],
            ['hex' => '#111111', 'role' => 'text', 'weight' => 120],
        ]));

        $this->assertNull(SitePalette::fromDeclared([]));
    }

    #[Test]
    public function it_takes_the_brand_colour_from_the_header_not_the_page(): void
    {
        // The shape of nearly every marketing site: a mostly white page with a
        // coloured band across the top. The most common colour by area is the
        // white, and it is the one thing that is certainly not the brand's.
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [[[47, 79, 67], 0.0, 0.25]],
        ));

        $this->assertNotNull($palette);
        $this->assertSame('#204040', $palette->fill);
    }

    #[Test]
    public function the_ink_is_whichever_of_the_sites_own_neutrals_reads_on_the_fill(): void
    {
        // A cream page rather than a white one. A brand whose light neutral is
        // warm should keep its cream — computing #ffffff would be a colour the
        // site does not use.
        $palette = SitePalette::fromPng($this->page(
            background: [243, 239, 230],
            bands: [[[47, 79, 67], 0.0, 0.30]],
        ));

        $this->assertNotNull($palette);
        $this->assertSame('#f0e0e0', $palette->ink);
    }

    #[Test]
    public function a_second_colour_only_counts_as_an_accent_if_it_is_a_different_colour(): void
    {
        // A header and a button in genuinely different hues — forest and
        // terracotta, about 150 degrees apart.
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [
                [[47, 79, 67], 0.0, 0.30],
                [[214, 83, 60], 0.60, 0.75],
            ],
        ));

        $this->assertNotNull($palette);
        $this->assertSame('#204040', $palette->fill);
        $this->assertSame('#d05030', $palette->accent);
    }

    #[Test]
    public function a_lighter_shade_of_the_brand_colour_is_not_an_accent(): void
    {
        // The failure this guards: a site using one colour at two brightnesses
        // would otherwise get an "accent" nobody can distinguish from the fill,
        // and every carousel would emphasise in a colour that looks like a
        // printing error rather than a decision.
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [
                [[47, 79, 67], 0.0, 0.30],
                [[94, 158, 134], 0.60, 0.75],
            ],
        ));

        $this->assertNotNull($palette);
        $this->assertNull($palette->accent);
    }

    #[Test]
    public function a_page_with_no_colour_on_it_suggests_nothing(): void
    {
        // Most of the web, and the case where a suggestion is worse than
        // silence: proposing #ffffff as a brand's fill looks like an answer,
        // and the operator who accepts it gets carousels the colour of a blank
        // page.
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [[[17, 17, 17], 0.0, 0.06]],
        ));

        $this->assertNull($palette);
    }

    /**
     * Pure black and pure white are colours a real page actually contains.
     *
     * Found by running this against a live site rather than a fixture. `0x00 /
     * 255` is `int(0)` in PHP — the one channel value that does not come back a
     * float — so the achromatic guard's strict comparison missed it and the hue
     * calculation divided by zero. Every generated fixture here quantises 255 to
     * 240 and so never produced a pure black; every screenshot of a real site
     * does, on the first letter of text.
     */
    #[Test]
    public function a_page_containing_pure_black_and_white_does_not_divide_by_zero(): void
    {
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [
                [[0, 0, 0], 0.0, 0.20],
                [[47, 79, 67], 0.40, 0.80],
            ],
        ));

        $this->assertNotNull($palette);
        $this->assertSame('#204040', $palette->fill);
    }

    /**
     * A photograph is not a brand colour, however much of the page it covers.
     *
     * The failure this whole weighting exists for. Cleaning Point's homepage is
     * a white page with a large photograph and a small teal button, and ranking
     * by area — however that area is weighted — answered "the sand in the hero
     * image", because that genuinely is the colour there is most of. Declining
     * is the right answer for such a page: proposing somebody's stock photo as
     * their brand colour is worse than proposing nothing.
     */
    #[Test]
    public function a_large_photograph_is_not_mistaken_for_a_painted_surface(): void
    {
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [],
            // Half the page, in the warm tones a room photograph is made of.
            photo: [0.25, 0.85],
        ));

        $this->assertNull($palette);
    }

    /**
     * The one vivid mark on a page that paints nothing is still its colour.
     *
     * The other half of the page above, and the case that used to fall through
     * the floor. Cleaning Point's homepage paints no surface at all — its whole
     * colour is the teal on a button — so every candidate failed the surface
     * test, and the palette came back null with the accent rule that was
     * written to save exactly this colour sitting unreached below the return.
     *
     * Chroma is what separates the two colours on such a page, and it separates
     * them far more cleanly than area or flatness can: measured on the real
     * homepage, the sand of the hero reads 0.125 and the teal 0.627.
     */
    #[Test]
    public function the_one_vivid_mark_on_an_unpainted_page_is_the_brand_colour(): void
    {
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [],
            photo: [0.25, 0.85],
            // A button, roughly where a hero's call to action sits.
            button: [[32, 192, 192], 0.06, 0.10, 0.20, 0.16],
        ));

        $this->assertNotNull($palette);
        $this->assertSame('#20c0c0', $palette->fill);
    }

    /**
     * And the photograph still does not become the second colour.
     *
     * A page with a painted surface has shown it makes colour decisions, so its
     * smaller colours are taken at face value. A page without one has shown the
     * opposite — everything on it that is not the single vivid mark came out of
     * a hero image — and offering the brightest of those as an accent is the
     * same error as offering the sand as a fill, one slot along.
     */
    #[Test]
    public function an_unpainted_page_takes_no_accent_from_its_photograph(): void
    {
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [],
            photo: [0.25, 0.85],
            button: [[32, 192, 192], 0.06, 0.10, 0.20, 0.16],
        ));

        $this->assertNotNull($palette);
        $this->assertNull($palette->accent);
    }

    /**
     * The same photograph loses to a genuinely painted band.
     *
     * The weighting is a ranking, not an exclusion: a page that has both a hero
     * image and a coloured header should answer with the header, and it should
     * do so because the header is flat rather than because the photograph was
     * filtered out on a threshold that moves.
     */
    #[Test]
    public function a_painted_band_beats_a_photograph_that_covers_more_of_the_page(): void
    {
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [[[47, 79, 67], 0.0, 0.22]],
            photo: [0.30, 0.95],
        ));

        $this->assertNotNull($palette);
        $this->assertSame('#204040', $palette->fill);
    }

    #[Test]
    public function a_few_stray_pixels_are_anti_aliasing_rather_than_a_brand(): void
    {
        // What `example.com` actually returned before this: a blue-grey it had
        // exactly one sample of, off the edge of a letter.
        $palette = SitePalette::fromPng($this->page(
            background: [255, 255, 255],
            bands: [[[80, 100, 190], 0.50, 0.505]],
        ));

        $this->assertNull($palette);
    }

    #[Test]
    public function bytes_that_are_not_an_image_are_not_a_palette(): void
    {
        // A renderer that answered 200 with an error page, which reaches this
        // as a string rather than as a failure.
        $this->assertNull(SitePalette::fromPng('not a png'));
        $this->assertNull(SitePalette::fromPng(''));
    }

    /**
     * What Cleaning Point's stylesheet actually declares, heaviest first.
     *
     * A fixture this time rather than a generated page, and for the opposite of
     * the reason the rest of this file avoids them: the census is a heuristic
     * about pictures and is best stated as intent, while this reads values a
     * page wrote down. These are the real ones, and they are the case the whole
     * declared path exists for — a navy the site paints with, a teal it marks
     * with, and 33° of hue between them.
     *
     * @return list<array{hex: string, role: string, weight: int}>
     */
    private function cleaningPoint(): array
    {
        return [
            ['hex' => '#ffffff', 'role' => 'background', 'weight' => 5635047],
            ['hex' => '#f4f8fb', 'role' => 'background', 'weight' => 5438283],
            ['hex' => '#002954', 'role' => 'background', 'weight' => 1406534],
            ['hex' => '#f0fcfb', 'role' => 'background', 'weight' => 51065],
            ['hex' => '#22cbc5', 'role' => 'background', 'weight' => 49016],
            ['hex' => '#ddf7f5', 'role' => 'background', 'weight' => 20308],
            ['hex' => '#1fa971', 'role' => 'background', 'weight' => 98],
            ['hex' => '#002954', 'role' => 'text', 'weight' => 97],
            ['hex' => '#75818f', 'role' => 'text', 'weight' => 76],
            ['hex' => '#263241', 'role' => 'text', 'weight' => 61],
            ['hex' => '#0e8f89', 'role' => 'text', 'weight' => 46],
            ['hex' => '#c3cad3', 'role' => 'border', 'weight' => 7],
            ['hex' => '#4dd7d1', 'role' => 'text', 'weight' => 2],
        ];
    }

    /**
     * A page as PNG bytes: a background with horizontal bands painted on it.
     *
     * @param  array{int, int, int}  $background
     * @param  list<array{array{int, int, int}, float, float}>  $bands  colour, top and bottom as a fraction of the height
     * @param  array{float, float}|null  $photo  a band of warm noise, standing in for a hero image
     * @param  array{array{int, int, int}, float, float, float, float}|null  $button  colour, then left, top, right and bottom as fractions
     */
    private function page(array $background, array $bands, ?array $photo = null, ?array $button = null): string
    {
        $width = 640;
        $height = 450;
        $image = imagecreatetruecolor($width, $height);

        $paint = static function (array $rgb, int $top, int $bottom) use ($image, $width): void {
            // Clamped rather than trusted. `imagecolorallocate` takes 0–255 and
            // returns false outside it, and a false colour paints nothing —
            // which would give a test that passes by drawing an empty image.
            $channel = static fn (int $value): int => max(0, min(255, $value));

            $colour = imagecolorallocate(
                $image,
                $channel($rgb[0]),
                $channel($rgb[1]),
                $channel($rgb[2]),
            );

            if ($colour === false) {
                throw new RuntimeException('The test image ran out of colours.');
            }

            imagefilledrectangle($image, 0, $top, $width, $bottom, $colour);
        };

        $paint($background, 0, $height);

        foreach ($bands as [$colour, $from, $to]) {
            $paint($colour, (int) ($height * $from), (int) ($height * $to));
        }

        if ($button !== null) {
            // A small solid rectangle: a brand's colour on a page that paints
            // nothing else with it. Small on purpose — the flatness probe
            // reaches three sampling steps, so a mark this size reads as
            // non-flat nearly everywhere it is measured, which is exactly why
            // it cannot qualify as a surface and exactly the shape the
            // vividness fallback exists to rescue.
            [$colour, $left, $top, $right, $bottom] = $button;

            $channel = static fn (int $value): int => max(0, min(255, $value));

            $allocated = imagecolorallocate(
                $image,
                $channel($colour[0]),
                $channel($colour[1]),
                $channel($colour[2]),
            );

            if ($allocated === false) {
                throw new RuntimeException('The test image ran out of colours.');
            }

            imagefilledrectangle(
                $image,
                (int) ($width * $left),
                (int) ($height * $top),
                (int) ($width * $right),
                (int) ($height * $bottom),
                $allocated,
            );
        }

        if ($photo !== null) {
            // Deterministic noise around a warm beige, which is what a room
            // photograph looks like to a histogram: one dominant family of
            // tones, varying everywhere. Seeded so a failure here is a change
            // in the reading rather than a change in the picture.
            mt_srand(20260817);

            for ($y = (int) ($height * $photo[0]); $y < (int) ($height * $photo[1]); $y++) {
                for ($x = (int) ($width / 2); $x < $width; $x++) {
                    $shade = imagecolorallocate(
                        $image,
                        max(0, min(255, 176 + mt_rand(-45, 45))),
                        max(0, min(255, 160 + mt_rand(-45, 45))),
                        max(0, min(255, 144 + mt_rand(-45, 45))),
                    );

                    if ($shade !== false) {
                        imagesetpixel($image, $x, $y, $shade);
                    }
                }
            }
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
