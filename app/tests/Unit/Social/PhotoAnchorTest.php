<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use App\Media\PhotoAnchor;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Where a cover's words can stand without covering the picture.
 *
 * Generated rather than fixtured, for the reason the palette tests give: a
 * fixture proves the answer for one photograph, and a generated frame states the
 * intent — "the subject is at the bottom" — and asserts the reading against it.
 */
final class PhotoAnchorTest extends TestCase
{
    /**
     * The failure this was written for.
     *
     * A gloved hand and a brush along a window sill, all of it in the lower
     * third, and a scrim fixed to the foot: the darkening landed on the only
     * part of the picture worth showing.
     */
    #[Test]
    public function words_go_to_the_top_when_the_subject_is_at_the_bottom(): void
    {
        $this->assertSame('top', PhotoAnchor::for($this->photo(busyHalf: 'bottom')));
    }

    #[Test]
    public function words_stay_at_the_foot_when_the_subject_is_at_the_top(): void
    {
        $this->assertSame('bottom', PhotoAnchor::for($this->photo(busyHalf: 'top')));
    }

    /**
     * Detail everywhere keeps the arrangement every other slide has.
     *
     * The bias is deliberate: a cover reads bottom-up and the eye leaves on the
     * last line, so the top has to be *clearly* calmer to earn the words rather
     * than merely winning a coin toss.
     */
    #[Test]
    public function an_evenly_busy_photograph_keeps_the_default(): void
    {
        $this->assertSame('bottom', PhotoAnchor::for($this->photo(busyHalf: 'both')));
    }

    #[Test]
    public function a_flat_colour_has_no_busy_half_to_avoid(): void
    {
        $this->assertSame('bottom', PhotoAnchor::for($this->photo(busyHalf: 'neither')));
    }

    /**
     * An unreadable picture is a reason to fall back, not to fail a slide.
     */
    #[Test]
    public function bytes_that_are_not_an_image_fall_back_to_the_foot(): void
    {
        $this->assertSame('bottom', PhotoAnchor::for('not a picture'));
    }

    /**
     * A frame that is calm in one half and noisy in the other.
     *
     * Seeded, so a failure is a change in the reading rather than a change in
     * the picture.
     */
    private function photo(string $busyHalf): string
    {
        $width = 540;
        $height = 675;
        $image = imagecreatetruecolor($width, $height);

        $flat = imagecolorallocate($image, 214, 210, 202);

        if ($flat === false) {
            throw new RuntimeException('The test image ran out of colours.');
        }

        imagefilledrectangle($image, 0, 0, $width, $height, $flat);

        mt_srand(20260820);

        $noisy = match ($busyHalf) {
            'top' => [[0, intdiv($height, 2) - 1]],
            'bottom' => [[intdiv($height, 2), $height - 1]],
            'both' => [[0, $height - 1]],
            default => [],
        };

        foreach ($noisy as [$from, $to]) {
            for ($y = $from; $y <= $to; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    // High-contrast speckle: what a photograph of a hand, a
                    // brush and a cloth looks like to a local-change reading.
                    $shade = imagecolorallocate(
                        $image,
                        mt_rand(0, 255),
                        mt_rand(0, 255),
                        mt_rand(0, 255),
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
