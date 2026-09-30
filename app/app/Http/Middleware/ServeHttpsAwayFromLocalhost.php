<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The incoming request is https too, not only the links built from it.
 *
 * {@see AppServiceProvider::forceHttpsAwayFromLocalhost()} made every *outgoing*
 * URL https, because the edge speaks https to the browser and plain http to
 * the origin. That left the request itself saying http, and anything that
 * reads the request's own URL still read the wrong one. Signed URLs are that:
 * the verification link is signed as `https://avyo.ai/email/verify/…` by a
 * queue worker, and checked against what the request says it is, which was
 * `http://avyo.ai/email/verify/…`. A different string, so every verification
 * link answered 403 "Invalid signature" — on the first thing a new customer
 * ever clicks.
 *
 * The same rule as the provider, deliberately: every host but this machine's
 * is served over https, whatever the last proxy wrote into `X-Forwarded-Proto`.
 * The forwarded proto and port are overwritten rather than trusted, because
 * with `TRUSTED_PROXIES=*` they win over the server variables, and they are
 * what said http in the first place.
 */
final class ServeHttpsAwayFromLocalhost
{
    /** The one place the app is genuinely served over http: `docker compose up` and the suite. */
    public const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    public static function isLocal(string $host): bool
    {
        return in_array($host, self::LOCAL_HOSTS, true);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isLocal($request->getHost())) {
            $request->server->set('HTTPS', 'on');
            $request->headers->set('X-Forwarded-Proto', 'https');
            // Dropped rather than set: the port then follows the Host header,
            // which is 443 implied for the public host, instead of the 80 the
            // edge's plain-http hop reports.
            $request->headers->remove('X-Forwarded-Port');
        }

        return $next($request);
    }
}
