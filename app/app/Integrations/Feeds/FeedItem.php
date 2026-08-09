<?php

declare(strict_types=1);

namespace App\Integrations\Feeds;

use Carbon\CarbonImmutable;

/**
 * One entry from a project's RSS whitelist (§4.1).
 *
 * The same shape whether it came out of RSS 2.0, RSS 1.0 or Atom, because the
 * listening contour has no interest in which dialect a publisher chose. What it
 * needs is the four things a signal is made of: something stable to deduplicate
 * on, a subject, a link, and when it happened.
 *
 * `id` is the feed's own `guid`, `atom:id` or — failing both — the link, and it
 * is what `Signal::fingerprintFor()` will not be given: the fingerprint is
 * computed from the title and the entities, precisely so that the same story in
 * two feeds under two guids collapses to one signal. This id is the narrower
 * check that stops one feed re-delivering its own entry every hour.
 *
 * `publishedAt` is nullable and honestly so. §5 gives the reactive band a TTL,
 * and a feed that omits a date should produce a signal whose age is unknown
 * rather than one that looks like it happened at the moment we read it.
 */
final readonly class FeedItem
{
    public function __construct(
        public string $feedUrl,
        public ?string $feedTitle,
        public string $id,
        public string $title,
        public ?string $url,
        public ?string $summary,
        public ?CarbonImmutable $publishedAt,
    ) {}
}
