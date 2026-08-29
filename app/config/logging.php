<?php

declare(strict_types=1);

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        /*
         * Not everything that goes wrong throws.
         *
         * A handful of places in this application catch a failure, decide the
         * run cannot continue, and write `Log::error` — the pipeline runner
         * abandoning a step, a Stripe webhook it could not make sense of, a
         * reply the social sender gave up on. Those are the real "somebody
         * should look at this" moments, and none of them reach the exception
         * handler, so none of them would reach Sentry through it.
         *
         * `error` and above only, deliberately. This application logs at
         * `warning` freely and for ordinary conditions — a feed that returned
         * nothing, a page that would not parse — and routing those to Sentry
         * would bury the seven things that matter under thousands that do not.
         *
         * Added to the stack in the Dockerfile and docker-compose.yml rather
         * than here, because the containers set LOG_CHANNEL themselves.
         */
        'sentry' => [
            'driver' => 'sentry',
            'level' => env('LOG_LEVEL_SENTRY', 'error'),
            'bubble' => true,

            /*
             * Messages only. Exceptions are already reported, once, by
             * Integration::handles() in bootstrap/app.php.
             *
             * Laravel's handler does not stop after the reportable callbacks —
             * it runs them and *then* logs the exception at error level with
             * the throwable in the context. With this channel in the stack
             * that record arrives here, the handler sees a Throwable and calls
             * captureException on it, and every unhandled request, command and
             * job failure lands in Sentry twice: once from the integration and
             * once from its own log line. Two issues to triage, two of every
             * alert, and double the quota for nothing.
             *
             * The trap this leaves, stated plainly because it is not obvious:
             * a `Log::error('...', ['exception' => $e])` where `$e` is a real
             * Throwable is now dropped by this handler rather than reported —
             * and nothing else catches it either, because it was caught rather
             * than thrown. Use `report($e)` for that, which is the idiom this
             * codebase already uses. Every `Log::error` here passes
             * `$e->getMessage()`, a string, so all of them still report.
             */
            'report_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
