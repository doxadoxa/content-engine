<?php

declare(strict_types=1);

namespace App\Audit\PageSpeed;

/**
 * One Lighthouse run, reduced to the numbers worth storing.
 *
 * The performance score and the three Core Web Vitals behind it. Everything
 * else Lighthouse returns — the full audit tree, the screenshots, the opportunity
 * list — is megabytes per page and is not what the screen shows.
 *
 * Every metric is nullable because a field-data lookup can legitimately return
 * a score with no LCP attached, and a nullable column that renders as `—` is
 * more honest than a zero that renders as "instant".
 */
final readonly class PageSpeedReading
{
    public function __construct(
        /** Lighthouse's performance category, 0–100. */
        public int $score,
        /** Largest Contentful Paint, milliseconds. */
        public ?int $largestContentfulPaintMs = null,
        /** Cumulative Layout Shift, unitless. */
        public ?float $cumulativeLayoutShift = null,
        /** Total Blocking Time, milliseconds. */
        public ?int $totalBlockingTimeMs = null,
        public string $strategy = 'mobile',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'lcp_ms' => $this->largestContentfulPaintMs,
            'cls' => $this->cumulativeLayoutShift,
            'tbt_ms' => $this->totalBlockingTimeMs,
            'strategy' => $this->strategy,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            score: (int) ($data['score'] ?? 0),
            largestContentfulPaintMs: isset($data['lcp_ms']) ? (int) $data['lcp_ms'] : null,
            cumulativeLayoutShift: isset($data['cls']) ? (float) $data['cls'] : null,
            totalBlockingTimeMs: isset($data['tbt_ms']) ? (int) $data['tbt_ms'] : null,
            strategy: (string) ($data['strategy'] ?? 'mobile'),
        );
    }
}
