<?php

declare(strict_types=1);

use App\Support\Observability\ScrubSentryPayloads;

/*
 * Where faults go, now that they go somewhere.
 *
 * Before this file existed the only trace of a broken request was a line on
 * stderr in a container nobody tails — which is how the scheduler managed to
 * stay dead for five days (see the comment on that service in
 * docker-compose.yml). Everything here is env-driven and inert when the DSN is
 * empty, so a checkout with no Sentry project behaves exactly as it did.
 *
 * Two settings below are load-bearing for privacy rather than for cost, and
 * both are marked. This application holds customers' unpublished drafts and
 * their site content; an error report is not a licence to copy that into a
 * third party. `send_default_pii` and the two `sql_bindings` switches are the
 * difference between a stack trace and an exfiltration channel.
 *
 * Sentry is named in config/legal.php as a subprocessor, and the privacy
 * policy renders that list. Changing what is sent here without changing what
 * is said there makes the published policy false — see the docblock on
 * config/legal.php for why that is the worse of the two failures.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    // Empty by default, and empty is a working configuration: the SDK becomes
    // a no-op rather than an error, which is what keeps `composer ci:check`
    // and a fresh clone offline. Pinned to '' for the suite in tests/bootstrap.php.
    // @see https://docs.sentry.io/concepts/key-terms/dsn-explainer/
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // @see https://spotlightjs.com/
    // 'spotlight' => env('SENTRY_SPOTLIGHT', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#logger
    // 'logger' => Sentry\Logger\DebugFileLogger::class, // By default this will log to `storage_path('logs/sentry.log')`

    // The release version of your application. The image sets SENTRY_RELEASE to
    // the commit it was built from; `?: null` because it sets the variable
    // whether or not a release was passed, and env() hands back '' rather than
    // null for one that is present and empty. An empty-string release is not the
    // same thing as no release: it would have the SDK report every server event
    // under a release named nothing.
    'release' => env('SENTRY_RELEASE') ?: null,

    // When left empty or `null` the Laravel environment will be used (usually discovered from `APP_ENV` in your `.env`)
    'environment' => env('SENTRY_ENVIRONMENT'),

    // Override the organization ID used for trace continuation checks.
    'org_id' => env('SENTRY_ORG_ID') === null ? null : (int) env('SENTRY_ORG_ID'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#sample_rate
    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    // One request in ten is traced. Errors are not sampled — `sample_rate`
    // above stays at 1.0 — because a fault you see a tenth of is a fault you
    // cannot reproduce. Performance is the opposite: a tenth of the traffic
    // describes the shape of it perfectly well, and the pipeline queues are
    // busy enough that tracing all of it would be expensive noise.
    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces_sample_rate
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? 0.1 : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    // Relative to the traced tenth above, not to all traffic — so this is one
    // request in a hundred carrying a profile. Needs the Excimer extension,
    // which the Dockerfile installs in `php-base`; without it the SDK simply
    // does not profile rather than failing, so a host PHP without the
    // extension is not a broken configuration.
    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#profiles_sample_rate
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? 0.1 : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    // Only continue incoming traces when the organization IDs are compatible with this SDK instance.
    'strict_trace_continuation' => env('SENTRY_STRICT_TRACE_CONTINUATION', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_logs
    'enable_logs' => env('SENTRY_ENABLE_LOGS', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_metrics
    'enable_metrics' => env('SENTRY_ENABLE_METRICS', true),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#log_flush_threshold
    'log_flush_threshold' => env('SENTRY_LOG_FLUSH_THRESHOLD') === null ? null : (int) env('SENTRY_LOG_FLUSH_THRESHOLD'),

    // The minimum log level that will be sent to Sentry as logs using the `sentry_logs` logging channel
    'logs_channel_level' => env('SENTRY_LOG_LEVEL', env('SENTRY_LOGS_LEVEL', env('LOG_LEVEL', 'debug'))),

    // Off, and it is not a tuning knob. Turning this on attaches the request
    // body, the cookies, the IP address and the authenticated user's email to
    // every event — which for this application means a customer's draft
    // arriving in Sentry because the request that carried it happened to throw.
    // The identifiers worth having are attached deliberately and narrowly
    // instead: see App\Http\Middleware\SentryContext, which sets a user id and
    // a project id and nothing else.
    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#send_default_pii
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    /*
     * What `send_default_pii => false` does not cover.
     *
     * That setting governs what the SDK collects on its own. It says nothing
     * about what this application hands over: a `Log::` context array becomes
     * a breadcrumb verbatim, and an outbound request records its query string
     * on both a breadcrumb and a span. Two places in this codebase put a
     * customer's prompt and a customer's search terms into exactly those, so
     * without these hooks the promise made in config/legal.php — that Sentry
     * "is not sent your content" — is untrue.
     *
     * Array callables, not closures: the production entrypoint runs
     * `php artisan config:cache`, which cannot serialise a closure and fails
     * the boot outright.
     */
    'before_breadcrumb' => [ScrubSentryPayloads::class, 'breadcrumb'],
    'before_send_transaction' => [ScrubSentryPayloads::class, 'transaction'],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_exceptions
    // 'ignore_exceptions' => [],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_transactions
    'ignore_transactions' => [
        // Ignore Laravel's default health URL
        '/up',
    ],

    // Breadcrumb specific configuration
    'breadcrumbs' => [
        // Capture Laravel logs as breadcrumbs
        'logs' => env('SENTRY_BREADCRUMBS_LOGS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as breadcrumbs
        'cache' => env('SENTRY_BREADCRUMBS_CACHE_ENABLED', true),

        // Capture Livewire components like routes as breadcrumbs
        'livewire' => env('SENTRY_BREADCRUMBS_LIVEWIRE_ENABLED', true),

        // Capture SQL queries as breadcrumbs
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query breadcrumbs.
        // Off deliberately: the query text is a useful breadcrumb, but the
        // bindings are the customer's content — the article body, the prompt,
        // the email address — and there is no version of "which row" that is
        // worth shipping the row itself for.
        'sql_bindings' => env('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED', false),

        // Capture queue job information as breadcrumbs
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),

        // Capture command information as breadcrumbs
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED', true),

        // Capture HTTP client request information as breadcrumbs
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture send notifications as breadcrumbs
        'notifications' => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
    ],

    // Performance monitoring specific configuration
    'tracing' => [
        // Trace queue jobs as their own transactions (this enables tracing for queue jobs)
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),

        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),

        // Capture SQL queries as spans
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query spans.
        // Off for the same reason as its breadcrumb twin above. A slow query
        // is diagnosed from its shape and its plan, not from the values that
        // happened to be in it.
        'sql_bindings' => env('SENTRY_TRACE_SQL_BINDINGS_ENABLED', false),

        // Capture where the SQL query originated from on the SQL query spans
        'sql_origin' => env('SENTRY_TRACE_SQL_ORIGIN_ENABLED', true),

        // Define a threshold in milliseconds for SQL queries to resolve their origin
        'sql_origin_threshold_ms' => env('SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS', 100),

        // Capture views rendered as spans
        'views' => env('SENTRY_TRACE_VIEWS_ENABLED', true),

        // Capture Livewire components as spans
        'livewire' => env('SENTRY_TRACE_LIVEWIRE_ENABLED', true),

        // Capture HTTP client requests as spans
        'http_client_requests' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as spans
        'cache' => env('SENTRY_TRACE_CACHE_ENABLED', true),

        // Capture Redis operations as spans (this enables Redis events in Laravel)
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),

        // Capture where the Redis command originated from on the Redis command spans
        'redis_origin' => env('SENTRY_TRACE_REDIS_ORIGIN_ENABLED', true),

        // Capture send notifications as spans
        'notifications' => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),

        // Enable tracing for requests without a matching route (404's)
        'missing_routes' => env('SENTRY_TRACE_MISSING_ROUTES_ENABLED', false),

        // Configures if the performance trace should continue after the response has been sent to the user until the application terminates
        // This is required to capture any spans that are created after the response has been sent like queue jobs dispatched using `dispatch(...)->afterResponse()` for example
        'continue_after_response' => env('SENTRY_TRACE_CONTINUE_AFTER_RESPONSE', true),

        // Capture AI agent interactions as spans (requires laravel/ai).
        // Left on, and worth being explicit about why that is safe here: these
        // spans carry the model, the token counts and the latency, and they
        // carry the prompt and completion *only* when `send_default_pii` is
        // true. It is false above and must stay false — this application
        // generates customers' drafts, and the prompt is the draft. Turning
        // that setting on silently converts this line into a copy of every
        // generation, which is the one thing the privacy policy promises does
        // not happen.
        'gen_ai' => env('SENTRY_TRACE_GEN_AI_ENABLED', true),

        // Capture AI invoke_agent spans
        'gen_ai_invoke_agent' => env('SENTRY_TRACE_GEN_AI_INVOKE_AGENT_ENABLED', true),

        // Capture AI chat spans
        'gen_ai_chat' => env('SENTRY_TRACE_GEN_AI_CHAT_ENABLED', true),

        // Capture AI execute_tool spans
        'gen_ai_execute_tool' => env('SENTRY_TRACE_GEN_AI_EXECUTE_TOOL_ENABLED', true),

        // Capture AI embeddings spans
        'gen_ai_embeddings' => env('SENTRY_TRACE_GEN_AI_EMBEDDINGS_ENABLED', true),

        // Enable the tracing integrations supplied by Sentry (recommended)
        'default_integrations' => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED', true),
    ],

];
