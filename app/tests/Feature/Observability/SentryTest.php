<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Illuminate\Log\Logger;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\Test;
use Sentry\Laravel\SentryHandler;
use Tests\TestCase;

/*
 * The three ways the Sentry integration goes wrong without anybody noticing.
 *
 * All three share a shape: nothing throws, no test fails, and the mistake is
 * only visible as an absence somewhere else — events that never arrive, or
 * events that arrive from a machine that should never have sent any.
 *
 * 1. The suite starts reporting. A developer with a DSN in their .env, or a
 *    stray override, and every deliberately-thrown test exception is posted to
 *    the production project. Note that Http::preventStrayRequests() does not
 *    protect against this: the Sentry PHP SDK carries its own transport and
 *    never touches the Http facade. The pin in tests/bootstrap.php is the only
 *    guard, which is why it is asserted rather than assumed.
 *
 * 2. The `sentry` log channel stops resolving. The containers run
 *    LOG_STACK=stderr,sentry, and a stack naming a channel that does not exist
 *    throws when the first line is written — so a typo here does not degrade
 *    logging, it breaks every request that logs.
 *
 * 3. The Content-Security-Policy blocks the browser SDK. `connect-src 'self'`
 *    makes the browser drop the POST to Sentry silently, with nothing in the
 *    console we collect and nothing in our logs. The frontend would be fully
 *    instrumented and permanently mute, and the only symptom is a project that
 *    looks reassuringly quiet.
 */
final class SentryTest extends TestCase
{
    #[Test]
    public function the_suite_cannot_report_to_sentry(): void
    {
        $this->assertEmpty(
            config('sentry.dsn'),
            'A DSN is configured under APP_ENV=testing, so the suite is posting its deliberate exceptions to a real Sentry project. See the pin in tests/bootstrap.php.',
        );
    }

    #[Test]
    public function the_sentry_log_channel_resolves_and_is_wired_to_sentry(): void
    {
        // The containers put this in LOG_STACK, and a stack referring to a
        // channel that cannot be built throws on the first write rather than
        // falling back — so resolving it at all is half the assertion.
        $channel = logger()->channel('sentry');

        $this->assertInstanceOf(Logger::class, $channel);

        // The other half: that it resolved to Sentry's handler rather than to
        // some default that quietly swallows the records. A channel that
        // builds and goes nowhere is the failure this test exists for.
        $monolog = $channel->getLogger();
        $this->assertInstanceOf(MonologLogger::class, $monolog);

        $handlers = $monolog->getHandlers();

        $this->assertNotEmpty(array_filter(
            $handlers,
            static fn (HandlerInterface $handler): bool => $handler instanceof SentryHandler,
        ), 'The sentry log channel built without a Sentry handler, so Log::error goes nowhere.');
    }

    #[Test]
    public function the_sentry_channel_only_takes_errors(): void
    {
        // This application logs at `warning` freely and for ordinary
        // conditions — a feed that returned nothing, a page that would not
        // parse. Letting those through would bury the handful of `Log::error`
        // calls that actually mean somebody should look.
        $this->assertSame('error', config('logging.channels.sentry.level'));
    }

    #[Test]
    public function the_sentry_channel_leaves_exceptions_to_the_exception_handler(): void
    {
        /*
         * Laravel runs its reportable callbacks and then logs the exception
         * anyway, with the throwable in the context. With this channel in the
         * stack and this flag on, every unhandled failure would reach Sentry
         * twice — once from Integration::handles() and once from its own log
         * line — which is two issues to triage and two of every alert.
         */
        $this->assertFalse(
            config('logging.channels.sentry.report_exceptions'),
            'The sentry log channel is reporting exceptions as well as messages, so every unhandled failure is sent to Sentry twice.',
        );
    }

    #[Test]
    public function the_csp_lets_the_browser_reach_the_configured_sentry(): void
    {
        config()->set('security.csp.sentry_browser_dsn', 'https://publickey@o123456.ingest.de.sentry.io/7891011');

        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString(
            'connect-src',
            $csp,
        );

        $this->assertMatchesRegularExpression(
            '/connect-src[^;]*https:\/\/o123456\.ingest\.de\.sentry\.io/',
            $csp,
            'The ingest origin is missing from connect-src, so the browser will drop every event before it leaves the page.',
        );

        // The DSN's public key is not a secret in the sense that leaking it is
        // dangerous, but a response header sent to everyone is no place for
        // it, and only the origin is needed.
        $this->assertStringNotContainsString('publickey', $csp);
    }

    #[Test]
    public function the_csp_stays_closed_when_no_sentry_is_configured(): void
    {
        config()->set('security.csp.sentry_dsn', null);
        config()->set('security.csp.sentry_browser_dsn', null);

        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString(
            'sentry.io',
            $csp,
            'An unconfigured deployment is widening its CSP for a host it never talks to.',
        );
    }

    #[Test]
    public function a_malformed_dsn_does_not_widen_the_policy(): void
    {
        // Failing closed matters more here than failing loudly: a request is
        // the wrong place to discover a configuration typo, and the safe way
        // to be wrong about connect-src is to allow less rather than more.
        config()->set('security.csp.sentry_browser_dsn', 'not-a-url');

        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringNotContainsString('not-a-url', $csp);
    }
}
