<?php

declare(strict_types=1);

/*
 * Avyo's own blog, written by Avyo.
 *
 * The blog is a receiver like any customer's site: a webhook channel on the
 * project that runs Avyo's own marketing points at `/blog/webhook`, and every
 * article the engine publishes there lands here. Nothing about the blog knows
 * it lives inside the engine — the only thing the two share is the secret.
 */
return [
    /*
     * The channel's secret, pasted from the webhook channel's settings. Unset,
     * the endpoint refuses everything with 503 rather than accepting unsigned
     * writes from the internet.
     */
    'webhook_secret' => env('BLOG_WEBHOOK_SECRET'),

    /* How far the two clocks may drift before a signature is refused. */
    'tolerance' => (int) env('BLOG_WEBHOOK_TOLERANCE', 300),

    /*
     * The language served at `/blog/{slug}`. Posts in any other locale live
     * at `/blog/{locale}/{slug}`, so a second language never moves the URLs
     * of the first.
     */
    'locale' => env('BLOG_LOCALE', 'en'),

    'per_page' => 12,
];
