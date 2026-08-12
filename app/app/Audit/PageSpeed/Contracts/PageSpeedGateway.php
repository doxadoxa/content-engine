<?php

declare(strict_types=1);

namespace App\Audit\PageSpeed\Contracts;

use App\Audit\Checks\PageWeightCheck;
use App\Audit\PageSpeed\PageSpeedReading;

/**
 * Somewhere to get a real browser's opinion of a page.
 *
 * A port rather than a call to Google inline, for the same three reasons every
 * other vendor here has one: the suite must not phone a vendor, an installation
 * without credentials must still be able to run a sweep, and the day this is
 * bought from somebody else should be one class.
 *
 * The distinction from {@see PageWeightCheck}, which measures a page too: that
 * one reports what our own crawler observed — time to first byte and HTML size,
 * free on every page fetched. This is Lighthouse: what a browser experiences
 * after it has parsed, fetched and rendered everything. Free versus quota'd is
 * exactly why both exist, and why only a handful of pages per sweep come here.
 */
interface PageSpeedGateway
{
    /** A short name, recorded on what it produces. */
    public function name(): string;

    /**
     * Whether it can be called at all.
     *
     * False is an ordinary state — most installations have no key — and every
     * caller must treat it as "there is no speed score", never as an error.
     */
    public function isConfigured(): bool;

    /**
     * Measure one page.
     *
     * Null when the vendor had nothing to say about this URL: a page it could
     * not render, a result with no performance category. That is a fact about
     * the page rather than an outage, and the caller records it as unmeasured.
     * Real failures — a rejected key, a quota, a timeout — throw, so the
     * pipeline's retry logic still has something to sort.
     */
    public function measure(string $url): ?PageSpeedReading;
}
