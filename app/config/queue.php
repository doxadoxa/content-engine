<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        // Discards whatever is pushed to it. The pipeline tests use it to hold
        // a run still without faking the queue: a fake cannot be undone, and
        // testing that a killed worker's run resumes means the queue has to
        // start working again partway through.
        'null' => [
            'driver' => 'null',
        ],

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            /*
             * Longer than the longest worker timeout, which is the whole rule.
             *
             * Laravel's default is 90, and the supervisors in config/horizon.php
             * run to 120 on `pipeline` and 900 on `pipeline-expensive`. Inverted
             * like that, Redis hands a still-running job to a second worker at
             * 90 seconds while the first is legally working for another
             * quarter of an hour — the queue manufacturing concurrency nobody
             * asked for, on exactly the jobs that call models and publish.
             *
             * The pipeline engine survives it by design (a step claim plus a
             * project advisory mutex), and that machinery stays: it also
             * protects against a worker being killed. But it was absorbing a
             * configuration fault rather than the rare case it was written for,
             * and a publish has no equivalent protection — a duplicated
             * delivery job is an article published twice.
             *
             * Raise this whenever a supervisor's timeout goes up. It went up:
             * `pipeline-expensive` is 2100 now, because a step on that queue was
             * asking for longer than the worker allowed and losing. 2700
             * keeps the same margin over it that 1200 kept over 900.
             *
             * Publishing does not run here any more — see `publishing` below —
             * because a number sized for a thirty-five-minute model call is
             * also how long a lost delivery waited before anything noticed.
             */
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 2_700),
            'block_for' => null,
            'after_commit' => false,
        ],

        /*
         * The same Redis, with a `retry_after` of its own, for publishing.
         *
         * `retry_after` belongs to a connection, not to a queue, so while
         * deliveries shared `redis` with the pipeline they inherited the 2700
         * seconds the longest model step needs. A delivery is one HTTP request
         * of at most `publishing.timeout`. When the worker holding one was
         * restarted by a deploy, Redis waited three quarters of an hour to
         * offer it again — and the offer is refused, because the job has one
         * try — so the sweep that actually recovers it could not safely run for
         * an hour. An owner watched "in progress" for seventy minutes on an
         * article that was never sent.
         *
         * 150 clears the `publishing` supervisor's 90-second timeout
         * (config/horizon.php) by a minute, which is the same rule the
         * paragraph above states for `redis`, and the 100-second delivery
         * lock that outlives that timeout. It also bounds how long
         * App\Publishing\StrandedDeliveries has to wait before sweeping.
         * PipelineTimeoutChainTest keeps the whole chain in order.
         */
        'publishing' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'publishing',
            'retry_after' => (int) env('PUBLISH_QUEUE_RETRY_AFTER', 150),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
