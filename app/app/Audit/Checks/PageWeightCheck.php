<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\PageCheck;
use App\Audit\PageFacts;
use App\Audit\PageSpeed\Contracts\PageSpeedGateway;
use App\Enums\AuditCheckGroup;

/**
 * What the crawl itself measured: how long the server took, and how much HTML
 * it sent.
 *
 * Not a substitute for {@see PageSpeedGateway} and not trying to be. Lighthouse
 * measures what a browser experiences, on a sample of pages a quota allows;
 * this measures two numbers that are already free on every page the crawler
 * touched. They answer different questions and the second one is the only one
 * available for page ninety of a hundred.
 *
 * Thresholds are the boundaries of "the server is the problem". A response over
 * two seconds is not a slow network, and half a megabyte of HTML before a
 * single image is a page that was assembled rather than written.
 */
class PageWeightCheck implements PageCheck
{
    private const int SLOW_MS = 2_000;

    private const int VERY_SLOW_MS = 5_000;

    private const int HEAVY_BYTES = 500_000;

    public static function key(): string
    {
        return 'page_weight';
    }

    public function label(): string
    {
        return 'Server response';
    }

    public function description(): string
    {
        return 'Measures how long the server took to answer and how much HTML it sent.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Performance;
    }

    /** @return list<CheckFinding> */
    public function run(PageFacts $page): array
    {
        $findings = [];

        if ($page->responseMs !== null && $page->responseMs >= self::VERY_SLOW_MS) {
            $findings[] = CheckFinding::medium(
                'The server took '.$this->seconds($page->responseMs).' to answer.',
                ['response_ms' => $page->responseMs],
            );
        } elseif ($page->responseMs !== null && $page->responseMs >= self::SLOW_MS) {
            $findings[] = CheckFinding::low(
                'The server took '.$this->seconds($page->responseMs).' to answer.',
                ['response_ms' => $page->responseMs],
            );
        }

        if ($page->htmlBytes !== null && $page->htmlBytes >= self::HEAVY_BYTES) {
            $findings[] = CheckFinding::low(
                'The HTML alone is '.$this->kilobytes($page->htmlBytes).', before any image or script.',
                ['html_bytes' => $page->htmlBytes],
            );
        }

        return $findings;
    }

    private function seconds(int $ms): string
    {
        return number_format($ms / 1000, 1).'s';
    }

    private function kilobytes(int $bytes): string
    {
        return number_format($bytes / 1024).' KB';
    }
}
