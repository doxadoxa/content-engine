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

    #[Test]
    public function bytes_that_are_not_an_image_are_not_a_palette(): void
    {
        // A renderer that answered 200 with an error page, which reaches this
        // as a string rather than as a failure.
        $this->assertNull(SitePalette::fromPng('not a png'));
        $this->assertNull(SitePalette::fromPng(''));
    }

    /**
     * A page as PNG bytes: a background with horizontal bands painted on it.
     *
     * @param  array{int, int, int}  $background
     * @param  list<array{array{int, int, int}, float, float}>  $bands  colour, top and bottom as a fraction of the height
     */
    private function page(array $background, array $bands): string
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

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
