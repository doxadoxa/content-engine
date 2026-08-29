<?php

declare(strict_types=1);

return [
    /*
     * The Content-Security-Policy in App\Http\Middleware\SecurityHeaders is
     * `connect-src 'self'`, which is the correct default and also the reason
     * the browser Sentry SDK reports nothing at all until told otherwise: the
     * browser drops the POST to the ingest host silently, with no error the
     * page can observe and nothing in our logs. An error monitor that has been
     * installed, is running, and can never deliver is worse than none.
     *
     * So the origins the browser is allowed to talk to are listed here rather
     * than being spelled into the header by hand. Both DSNs are read because
     * the browser and the server are separate Sentry projects: usually they
     * share an ingest host and this collapses to one entry, but they need not,
     * and a deployment that sets only one of the two should still work.
     *
     * Only the scheme and host are ever used — see the middleware. A DSN
     * contains a public key, and a public key does not belong in a header we
     * send to everyone.
     */
    'csp' => [
        'sentry_dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),
        'sentry_browser_dsn' => env('VITE_SENTRY_DSN'),
    ],

    'outbound' => [
        'require_https' => (bool) env(
            'OUTBOUND_REQUIRE_HTTPS',
            env('APP_ENV', 'production') === 'production',
        ),
        'allow_unresolved_hosts' => (bool) env('OUTBOUND_ALLOW_UNRESOLVED_HOSTS', false),
        'allowed_ports' => array_values(array_filter(array_map(
            static fn (string $port): int => (int) trim($port),
            explode(',', (string) env('OUTBOUND_ALLOWED_PORTS', '80,443')),
        ))),
    ],
];
