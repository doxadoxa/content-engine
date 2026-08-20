<?php

declare(strict_types=1);

namespace App\Media;

use App\Support\Brand\SitePalette;
use GdImage;

/**
 * Which end of a photograph the words can stand on.
 *
 * The cover sets its hook over a generated photograph behind a scrim, and the
 * scrim was fixed to the foot of the frame. That is the right default and the
 * wrong rule: the first cover it met was a gloved hand drawing a brush along a
 * window sill, all of it in the lower third, so the darkening landed squarely on
 * the only part of the picture worth showing and the hook sat on top of a hand.
 *
 * **Busyness, not saliency.** This does not know what a hand is. It measures how
 * much a neighbourhood changes — the same reading
 * {@see SitePalette} uses to tell a painted band from a
 * photograph — and takes the quieter half as the one that can carry type. A
 * subject is detailed and a wall, a sky or a worktop is not, which is enough to
 * be right about where the picture is empty without being right about what fills
 * it.
 *
 * **Biased to the foot.** A cover reads bottom-up: the eye leaves on the last
 * line, which is where "Swipe" is. So the top only wins when it is *clearly*
 * calmer — see {@see MARGIN} — and a picture with detail spread evenly keeps the
 * arrangement every other slide in the deck has.
 */
final class PhotoAnchor
{
    /**
     * How much calmer the top has to be before the words move up there.
     *
     * A ratio rather than a difference because the scale is arbitrary: what
     * matters is that one half is substantially quieter than the other, not by
     * how many units. A quarter, which on the covers this was built against
     * separates "the subject is at the bottom" from "there is detail
     * everywhere" without either being a close call.
     */
    private const float MARGIN = 0.75;

    /** Sampled at this many rows, whatever the picture's size. */
    private const int ROWS = 48;

    /**
     * Where the hook should sit: `top` or `bottom`.
     *
     * Bottom for anything that cannot be read, which is the arrangement the
     * cover had before this existed — an unreadable photograph is a reason to
     * fall back to the default, not to fail a slide.
     */
    public static function for(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return 'bottom';
        }

        try {
            [$top, $bottom] = self::halves($image);
        } finally {
            imagedestroy($image);
        }

        // Both empty means a flat colour, and a flat colour has no busy half to
        // avoid. The default stands.
        if ($top <= 0.0 && $bottom <= 0.0) {
            return 'bottom';
        }

        return $top < $bottom * self::MARGIN ? 'top' : 'bottom';
    }

    /**
     * Mean local change in each half, top first.
     *
     * @return array{float, float}
     */
    private static function halves(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor($height / self::ROWS));
        $middle = intdiv($height, 2);

        $sums = [0.0, 0.0];
        $counts = [0, 0];

        for ($y = 0; $y < $height; $y += $step) {
            // The band across the middle is where a scrim fades either way, so
            // it belongs to neither half and would only blur the comparison.
            if (abs($y - $middle) < $step * 2) {
                continue;
            }

            $half = $y < $middle ? 0 : 1;

            for ($x = 0; $x < $width; $x += $step) {
                $sums[$half] += self::change($image, $x, $y, $step, $width, $height);
                $counts[$half]++;
            }
        }

        return [
            $counts[0] > 0 ? $sums[0] / $counts[0] : 0.0,
            $counts[1] > 0 ? $sums[1] / $counts[1] : 0.0,
        ];
    }

    /**
     * How different this point is from its neighbours, 0–1.
     *
     * Four neighbours at one sampling step, which is deliberately coarse: a
     * tight probe measures grain and film noise, and every photograph has those
     * everywhere. What separates a hand from a wall is change at the scale of
     * the thing, not at the scale of the pixel.
     */
    private static function change(GdImage $image, int $x, int $y, int $step, int $width, int $height): float
    {
        $here = self::channels($image, $x, $y);
        $worst = 0.0;

        foreach ([[$step, 0], [-$step, 0], [0, $step], [0, -$step]] as [$dx, $dy]) {
            $nx = min($width - 1, max(0, $x + $dx));
            $ny = min($height - 1, max(0, $y + $dy));

            $there = self::channels($image, $nx, $ny);
            $difference = 0;

            foreach ([0, 1, 2] as $channel) {
                $difference = max($difference, abs($here[$channel] - $there[$channel]));
            }

            $worst = max($worst, $difference / 255);
        }

        return $worst;
    }

    /**
     * @return array{int, int, int}
     */
    private static function channels(GdImage $image, int $x, int $y): array
    {
        $rgb = imagecolorat($image, $x, $y);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }
}
