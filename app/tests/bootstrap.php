<?php

declare(strict_types=1);

/*
 * The suite's environment, forced before anything reads it.
 *
 * This is not where these settings would naturally live — phpunit.xml has an
 * <env> element for exactly this, with a force="true" attribute that promises
 * to override an existing value. It does not deliver inside the container:
 * PHPUnit writes to $_ENV and putenv(), while Dotenv's default adapter order
 * consults $_SERVER first, and $_SERVER is where Docker's `environment:` block
 * lands. The ambient development values win, silently.
 *
 * What that costs is not subtle. DB_DATABASE stays pointed at the development
 * database and RefreshDatabase drops its tables on the first test; CACHE_STORE
 * stays on the shared Redis and one test's writes are visible to the next. Both
 * happened here before this file existed.
 *
 * So: one place, all three superglobals, ahead of the autoloader.
 */

$overrides = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    // Cheap hashing — the suite creates users constantly and does not care how
    // expensive a password is to verify.
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',

    // Postgres, not sqlite: pgvector, jsonb and partial indexes are what the
    // engine stands on from phase 3 onward, and a suite that is green on sqlite
    // proves nothing about any of them.
    //
    // DB_HOST and DB_PORT are deliberately absent — they differ between the
    // container (postgres:5432), the host (127.0.0.1:5437) and CI
    // (127.0.0.1:5432), and each of those sets them correctly already.
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'content_engine_test',
    'DB_URL' => '',

    // The social presence stays on for the suite. It ships off (see
    // config/social.php) because a deployment without a Meta app has nothing
    // for it to do, but "off" is a deployment's answer and not a fact about the
    // code: phases 12.1–12.6 are most of what the suite covers, and a default
    // that reached the tests would turn several hundred of them green by
    // deleting the feature they exercise. The off state has its own tests —
    // tests/Feature/Social/FeaturePresenceTest.php — which set this back to
    // false before the application boots, because the switch is read while
    // config and routes are being built and cannot be moved afterwards.
    //
    // Set here rather than in phpunit.xml's <env> for the reason at the top of
    // this file: inside the container those values lose to $_SERVER.
    'SOCIAL_PRESENCE_ENABLED' => 'true',

    // No renderer, unless a test asks for one.
    //
    // The compose file gives every container a RENDERER_URL, which is right for
    // running the app and wrong for the suite: a test that did not think about
    // carousels would reach a real service over the network, and a suite that
    // goes out to a container is a suite that fails on a laptop with the stack
    // down. The tests that do care set the config themselves and fake the HTTP.
    'RENDERER_URL' => '',

    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',

    // Empty is how the Sentry SDK is switched off, and the suite has to be
    // switched off rather than merely quiet: a developer with a DSN in their
    // .env would otherwise post every deliberately-thrown test exception to
    // the production project, and the run would take the network round trip
    // for each one. Note that Http::preventStrayRequests() in TestCase does
    // *not* catch this — the PHP SDK has its own transport and never touches
    // the Http facade — so this line is the only thing keeping the suite
    // offline. Both names, because config/sentry.php falls back from the
    // first to the second.
    'SENTRY_LARAVEL_DSN' => '',
    'SENTRY_DSN' => '',
    // Reserved .test hosts are used behind Http::fake throughout the suite.
    // Literal/private addresses are still rejected by the outbound policy.
    'OUTBOUND_ALLOW_UNRESOLVED_HOSTS' => 'true',
];

foreach ($overrides as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
