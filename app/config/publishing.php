<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retry ladder
    |--------------------------------------------------------------------------
    |
    | §6.2, in seconds: 1m → 5m → 30m → 2h → 12h. Five attempts, then dead
    | letter. Published verbatim in the contract, so a receiver operator can
    | plan around it — which means it is not a number to tune casually.
    |
    */

    'backoff' => [60, 300, 1_800, 7_200, 43_200],

    /*
    |--------------------------------------------------------------------------
    | Signing
    |--------------------------------------------------------------------------
    |
    | How far apart the two clocks may be before a signature is refused. Five
    | minutes is the contract's published tolerance.
    |
    */

    'timestamp_tolerance' => (int) env('PUBLISH_TIMESTAMP_TOLERANCE', 300),

    'contract_version' => 1,

    'timeout' => (int) env('PUBLISH_TIMEOUT', 15),

    'queue' => env('PUBLISH_QUEUE', 'pipeline'),

    /*
    |--------------------------------------------------------------------------
    | Stranded deliveries
    |--------------------------------------------------------------------------
    |
    | How long a delivery may sit at `pending` before `publish:sweep-stranded`
    | presumes the worker that had it will never come back. The default clears
    | the Redis connection's `retry_after` plus the Threads publisher's longest
    | lease — see App\Publishing\StrandedDeliveries, which explains the
    | arithmetic and refuses to go below the lease whatever is configured here.
    |
    */

    'stranded_after' => (int) env('PUBLISH_STRANDED_AFTER', 1_800),

    // Deterministic lookup key for pull tokens. Keep this separate from the
    // encrypted token and stable across APP_KEY rotations.
    'pull_token_hash_key' => env('PULL_TOKEN_HASH_KEY') ?: env('APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Social formats (§8.1–8.2)
    |--------------------------------------------------------------------------
    |
    | The shape of a post per channel. Here rather than in the repurpose step,
    | because §8.1 asks for format prompts to come from configuration and the
    | brief — which is what lets a third channel be an enum case and a line
    | here rather than a change to the pipeline.
    |
    | The voice comes from the brand brief; these say only how long and in what
    | shape.
    |
    */

    'formats' => [
        'linkedin' => 'Write 3 to 5 short paragraphs. Open with the single most useful fact. '
            .'No hashtags, no emoji, no "thoughts?" at the end.',
        'x' => 'Write at most 260 characters. One idea. No hashtags.',
        'telegram' => 'Write 2 short paragraphs with a plain link at the end. No markdown headings.',
    ],

];
