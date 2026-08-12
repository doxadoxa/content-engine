<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\PageCheck;
use App\Audit\PageFacts;
use App\Enums\AuditCheckGroup;

/**
 * Images: described, sized, and in a format from this decade.
 *
 * One finding per fault rather than one per image. A page built from a template
 * has the same omission on every picture, and twelve rows saying "no alt text"
 * is a list an operator scrolls past — where "9 images have no alt text", with
 * the sources in the detail, is a job.
 *
 * Filed under Performance rather than SEO because two of its three faults are
 * about bytes and layout shift. Alt text is the exception and stays here anyway:
 * splitting one check across two scores to be pedantic about one finding would
 * make the groups harder to explain than they are worth.
 */
class ImageOptimizationCheck implements PageCheck
{
    /**
     * Formats a browser can decode efficiently. Anything else is a JPEG or PNG
     * being sent where a third of the bytes would do.
     *
     * @var list<string>
     */
    private const array MODERN_FORMATS = ['webp', 'avif', 'svg'];

    public static function key(): string
    {
        return 'image_optimization';
    }

    public function label(): string
    {
        return 'Image optimization';
    }

    public function description(): string
    {
        return 'Checks images for alt text, explicit dimensions, and a modern file format.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Performance;
    }

    /** @return list<CheckFinding> */
    public function run(PageFacts $page): array
    {
        if ($page->images === []) {
            return [];
        }

        $findings = [];

        $missingAlt = $this->sources($page, static fn (array $image): bool => $image['alt'] === null);

        if ($missingAlt !== []) {
            $findings[] = CheckFinding::low(
                $this->count($missingAlt, 'image has', 'images have').' no alt text.',
                ['images' => $missingAlt],
            );
        }

        $missingDimensions = $this->sources($page, static fn (array $image): bool => ! $image['has_dimensions']);

        if ($missingDimensions !== []) {
            $findings[] = CheckFinding::low(
                $this->count($missingDimensions, 'image has', 'images have')
                    .' no width and height, so the page shifts as they load.',
                ['images' => $missingDimensions],
            );
        }

        $legacy = $this->sources($page, static fn (array $image): bool => $image['format'] !== ''
            && ! in_array($image['format'], self::MODERN_FORMATS, true));

        if ($legacy !== []) {
            $findings[] = CheckFinding::low(
                $this->count($legacy, 'image is', 'images are').' served in a dated format.',
                ['images' => $legacy],
            );
        }

        return $findings;
    }

    /**
     * @param  callable(array{src: string, alt: string|null, has_dimensions: bool, format: string}): bool  $matches
     * @return list<string>
     */
    private function sources(PageFacts $page, callable $matches): array
    {
        $sources = [];

        foreach ($page->images as $image) {
            if ($matches($image)) {
                $sources[] = $image['src'];
            }
        }

        // Capped: the detail is evidence for a number, not a full inventory, and
        // a page with two hundred pictures should not write two hundred strings
        // into a json column.
        return array_slice(array_values(array_unique($sources)), 0, 10);
    }

    /**
     * @param  list<string>  $sources
     */
    private function count(array $sources, string $singular, string $plural): string
    {
        return count($sources).' '.(count($sources) === 1 ? $singular : $plural);
    }
}
