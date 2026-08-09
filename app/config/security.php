<?php

declare(strict_types=1);

return [
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
