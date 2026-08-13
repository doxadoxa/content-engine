<?php

declare(strict_types=1);

namespace App\Audit\PageSpeed;

use App\Audit\PageSpeed\Contracts\PageSpeedGateway;
use RuntimeException;

/**
 * The browser the suite runs against.
 *
 * Scriptable per URL, because the questions the tests ask are "does a sweep
 * with one slow page score lower than one without" and "does an installation
 * with no key end up with a null speed score rather than a zero" — and neither
 * survives a fixture that answers differently each run.
 */
class FakePageSpeed implements PageSpeedGateway
{
    /** @var array<string, PageSpeedReading|null> */
    private array $scripted = [];

    /** @var list<string> */
    private array $measured = [];

    private int $defaultScore = 92;

    private bool $configured = true;

    private bool $failing = false;

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /** No key: every caller must treat this as "no speed score", not an error. */
    public function unconfigured(): self
    {
        $this->configured = false;

        return $this;
    }

    public function scoring(int $score): self
    {
        $this->defaultScore = $score;

        return $this;
    }

    /** Answer for one URL — a reading, or null for "nothing to say about it". */
    public function script(string $url, ?PageSpeedReading $reading): self
    {
        $this->scripted[$url] = $reading;

        return $this;
    }

    /** Make the vendor itself fail, which a step must let the runner retry. */
    public function failing(): self
    {
        $this->failing = true;

        return $this;
    }

    /** @return list<string> */
    public function measuredUrls(): array
    {
        return $this->measured;
    }

    public function measure(string $url): ?PageSpeedReading
    {
        if ($this->failing) {
            throw new RuntimeException('The fake PageSpeed gateway was told to fail.');
        }

        $this->measured[] = $url;

        if (array_key_exists($url, $this->scripted)) {
            return $this->scripted[$url];
        }

        return new PageSpeedReading(
            score: $this->defaultScore,
            largestContentfulPaintMs: 1_800,
            cumulativeLayoutShift: 0.02,
            totalBlockingTimeMs: 120,
        );
    }
}
