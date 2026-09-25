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
    | the Redis connection's `retry_after` with room to spare — see
    | App\Publishing\StrandedDeliveries, which explains the arithmetic and
    | refuses to go below ten minutes whatever is configured here.
    |
    */

    'stranded_after' => (int) env('PUBLISH_STRANDED_AFTER', 1_800),

    // Deterministic lookup key for pull tokens. Keep this separate from the
    // encrypted token and stable across APP_KEY rotations.
    'pull_token_hash_key' => env('PULL_TOKEN_HASH_KEY') ?: env('APP_KEY'),

];
