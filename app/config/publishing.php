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

    /*
    |--------------------------------------------------------------------------
    | Delivery lock
    |--------------------------------------------------------------------------
    |
    | How long one attempt holds its delivery against a second attempt, a
    | replay, a schedule change and the stranded sweep — and how long a native
    | page operation holds its own, which runs on the same workers. Longer than the `publishing` supervisor's
    | timeout (90, config/horizon.php), because the worker's timeout is the
    | only hard bound on how long an attempt can run: a lock that expired
    | first would let the sweep decide a row being sent was abandoned. Shorter
    | than the connection's `retry_after`, so a killed worker's lock is gone
    | before anything could legitimately want it. PipelineTimeoutChainTest
    | holds both.
    |
    */

    'lock_seconds' => 100,

    /*
    |--------------------------------------------------------------------------
    | Where deliveries run
    |--------------------------------------------------------------------------
    |
    | Their own lane: the `publishing` connection in config/queue.php and the
    | `publishing` supervisor in config/horizon.php, both sized for one HTTP
    | request rather than for a model call. Every delivery and page operation
    | job sets both on itself, so no dispatch site has to remember them.
    |
    | Page operations take the same workers under a queue name of their own.
    | The stranded sweep counts the delivery queue to tell a backlog from a
    | lost job, and a batch of page operations is not a delivery backlog.
    |
    | Not configurable, either of them. The lock, the worker's timeout, the
    | connection's `retry_after` and the stranded threshold are one chain, and
    | a queue name from the environment is a way to put deliveries on a
    | worker the chain was not worked out for — `PUBLISH_QUEUE=pipeline`, left
    | over from before this lane existed, would have done exactly that.
    |
    */

    'connection' => 'publishing',

    'queue' => 'publishing',

    'pages_queue' => 'publishing-pages',

    /*
    |--------------------------------------------------------------------------
    | Stranded deliveries
    |--------------------------------------------------------------------------
    |
    | How long a delivery may sit unattempted — `pending` since it was queued,
    | or `retrying` past the time it was due — before `publish:sweep-stranded`
    | presumes the worker that had it will never come back. It must stay above
    | the `publishing` connection's `retry_after` (config/queue.php), or the
    | sweep can re-dispatch a job a worker is still holding. See
    | App\Publishing\StrandedDeliveries, which explains the arithmetic and
    | raises anything configured here to clear `retry_after` by a sweep.
    |
    */

    'stranded_after' => (int) env('PUBLISH_STRANDED_AFTER', 240),

    // Deterministic lookup key for pull tokens. Keep this separate from the
    // encrypted token and stable across APP_KEY rotations.
    'pull_token_hash_key' => env('PULL_TOKEN_HASH_KEY') ?: env('APP_KEY'),

];
