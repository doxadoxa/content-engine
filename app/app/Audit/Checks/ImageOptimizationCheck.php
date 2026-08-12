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

    /** Sources named in the detail. Enough to go and look, not an inventory. */
    private const int MAX_EXAMPLES = 10;

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

        $missingAlt = $this->matching($page, static fn (array $image): bool => $image['alt'] === null);

        if ($missingAlt['count'] > 0) {
            $findings[] = CheckFinding::low(
                $this->count($missingAlt, 'image has', 'images have').' no alt text.',
                ['images' => $missingAlt['examples']],
            );
        }

        $missingDimensions = $this->matching($page, static fn (array $image): bool => ! $image['has_dimensions']);

        if ($missingDimensions['count'] > 0) {
            $findings[] = CheckFinding::low(
                $this->count($missingDimensions, 'image has', 'images have')
                    .' no width and height, so the page shifts as they load.',
                ['images' => $missingDimensions['examples']],
            );
        }

        $legacy = $this->matching($page, static fn (array $image): bool => $image['format'] !== ''
            && ! in_array($image['format'], self::MODERN_FORMATS, true));

        if ($legacy['count'] > 0) {
            $findings[] = CheckFinding::low(
                $this->count($legacy, 'image is', 'images are').' served in a dated format.',
                ['images' => $legacy['examples']],
            );
        }

        return $findings;
    }

    /**
     * How many images match, and a few of them by name.
     *
     * Counted and sampled separately, on purpose. The number is every matching
     * `<img>` on the page, because that is what the summary claims and what an
     * operator will count when they go and look. The examples are capped and
     * deduplicated, because the detail is evidence rather than an inventory and
     * one source repeated across a page is one file to fix.
     *
     * Conflating the two — counting the capped list — reported a page with fifty
     * missing alt attributes as having exactly ten, every time. A wrong number
     * that plausible is one nobody ever goes and checks.
     *
     * @param  callable(array{src: string, alt: string|null, has_dimensions: bool, format: string}): bool  $matches
     * @return array{count: int, examples: list<string>}
     */
    private function matching(PageFacts $page, callable $matches): array
    {
        $count = 0;
        $examples = [];

        foreach ($page->images as $image) {
            if (! $matches($image)) {
                continue;
            }

            $count++;

            if (count($examples) < self::MAX_EXAMPLES && ! in_array($image['src'], $examples, true)) {
                $examples[] = $image['src'];
            }
        }

        return ['count' => $count, 'examples' => $examples];
    }

    /**
     * @param  array{count: int, examples: list<string>}  $matching
     */
    private function count(array $matching, string $singular, string $plural): string
    {
        return $matching['count'].' '.($matching['count'] === 1 ? $singular : $plural);
    }
}
