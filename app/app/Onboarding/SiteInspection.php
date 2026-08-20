<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Support\Brand\SitePalette;

/**
 * One visit to a site, and everything that visit learned.
 *
 * The picture and the stylesheet together, because they are read in the same
 * page load and separating them into two calls would mean opening somebody
 * else's website twice to answer one question — twice the wait for the operator
 * and twice the load on their server, with the risk that the second visit reads
 * a different page than the first photographed.
 *
 * **The colours here are declared, not observed.** Each carries the role it was
 * found in and a weight: a background weighs the area it covers, text and
 * borders weigh a count, and a custom property on the root weighs one for being
 * a name the brand chose to give a colour. {@see SitePalette}
 * is what turns them into three.
 */
final readonly class SiteInspection
{
    /**
     * @param  string  $png  the page as an image, always present
     * @param  list<array{hex: string, role: string, weight: int}>  $colours  heaviest first, empty where the page could not be read
     * @param  list<string>  $fonts  the families the page sets on its headings and body
     */
    public function __construct(
        public string $png,
        public array $colours = [],
        public array $fonts = [],
    ) {}

    /**
     * The typeface the brand writes in, or null where the page named none.
     *
     * The first of the families rather than all of them: the list is a heading
     * font and a body font, and where a site has one of each the heading is the
     * one somebody would call the brand's.
     */
    public function font(): ?string
    {
        return $this->fonts[0] ?? null;
    }

    /**
     * The distinct colours, heaviest first, one row per hex.
     *
     * Roles are collapsed here because the screen shows colours rather than
     * cascade: a teal that is a button's background and its own hover text is
     * one colour a person would point at, not two.
     *
     * @return list<string>
     */
    public function swatches(int $limit = 8): array
    {
        $weights = [];

        foreach ($this->colours as $colour) {
            $hex = strtolower($colour['hex']);
            $weights[$hex] = ($weights[$hex] ?? 0) + $colour['weight'];
        }

        arsort($weights);

        return array_slice(array_keys($weights), 0, max(1, $limit));
    }
}
