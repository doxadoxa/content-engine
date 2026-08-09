<?php

declare(strict_types=1);

return [

    /* Where generated images land. S3-compatible in any real deployment. */
    'disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | AtlasCloud / Seedream (§8.3)
    |--------------------------------------------------------------------------
    |
    | `model` takes reference pictures and is what keeps a set of images looking
    | like one article; `text_model` is what a first image with no references
    | goes to, because the `edit` variant refuses an empty reference list.
    |
    */

    'atlas' => [
        'api_key' => env('ATLASCLOUD_API_KEY'),
        'base_url' => env('ATLASCLOUD_BASE_URL', 'https://api.atlascloud.ai/api/v1'),
        // Confirmed against GET /models on a live account. The docs page shows
        // `seedream-3.0` in its example and the API does not know that name;
        // these provider-prefixed ones are what it actually serves.
        'model' => env('SEEDREAM_MODEL', 'bytedance/seedream-v4.5/edit'),
        'text_model' => env('SEEDREAM_TEXT_MODEL', 'bytedance/seedream-v4.5'),
        'poll_seconds' => (int) env('IMAGE_POLL_SECONDS', 3),
        'ready_timeout' => (int) env('IMAGE_READY_TIMEOUT', 300),
        'timeout' => (int) env('ATLASCLOUD_TIMEOUT', 60),
        'download_timeout' => (int) env('IMAGE_DOWNLOAD_TIMEOUT', 300),
        // Per picture. CONFIRM against the vendor's pricing before trusting a
        // cost report — an image is the most expensive thing a unit buys.
        'cost_micros' => (int) env('IMAGE_COST_MICROS', 40_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hero images
    |--------------------------------------------------------------------------
    |
    | 1200×630 is what §9 of the spec asks for: the size a social card wants.
    |
    */

    /*
     * Illustrations inside the body, one after a section heading.
     *
     * An article with a single picture at the top and two thousand words under
     * it reads as a wall however good the prose is. Three is what a comparable
     * published article carries, and it is the number a reader scrolls past
     * without noticing the article is long.
     *
     * Each one is a paid generation, so the count is a config value and not a
     * constant: this is the single largest per-article cost.
     */
    'inline' => [
        'count' => (int) env('INLINE_IMAGE_COUNT', 3),
        'width' => (int) env('INLINE_IMAGE_WIDTH', 1200),
        'height' => (int) env('INLINE_IMAGE_HEIGHT', 800),
    ],

    'hero' => [
        'width' => (int) env('HERO_WIDTH', 1200),
        'height' => (int) env('HERO_HEIGHT', 630),
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal linking (§8.4)
    |--------------------------------------------------------------------------
    |
    | How many links to put in an article, and how far apart two units may be
    | and still count as related. Distance is cosine, so 0 is identical and 1 is
    | unrelated — the ceiling exists so a thin corpus links to nothing rather
    | than to the least unrelated thing it can find.
    |
    */

    'links' => [
        'per_unit' => (int) env('INTERNAL_LINKS_PER_UNIT', 3),
        'max_distance' => (float) env('INTERNAL_LINKS_MAX_DISTANCE', 0.75),
    ],

];
