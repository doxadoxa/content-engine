<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function __construct(private readonly Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = $this->vite->useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        $scriptSources = ["'self'", "'nonce-{$nonce}'"];
        $connectSources = ["'self'"];

        if (app()->environment('local')) {
            // Vite's development client needs eval for source transforms and a
            // WebSocket for HMR. Production never receives either exception.
            $scriptSources[] = "'unsafe-eval'";
            $scriptSources[] = 'http://localhost:5173';
            $connectSources[] = 'ws://localhost:5173';
            $connectSources[] = 'http://localhost:5173';
        }

        // Without this the browser SDK is installed and mute: `connect-src
        // 'self'` makes the browser drop its POST before it leaves the page,
        // and it does so silently. Nothing is added when no DSN is configured,
        // so the header stays exactly as strict as it was.
        foreach ($this->sentryOrigins() as $origin) {
            $connectSources[] = $origin;
        }

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSources),
            // Radix and positioning primitives use inline style attributes.
            // Script remains nonce-only; styles can be tightened separately.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connectSources),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * The Sentry ingest origins the browser may post to, derived from the DSNs.
     *
     * Derived rather than written down, because the host is not a constant: it
     * carries the organisation id and the storage region, so a project created
     * in the EU and one created in the US do not share it, and a hardcoded
     * `*.ingest.sentry.io` would be a header that looks correct and blocks
     * everything. The DSN already holds the answer.
     *
     * Scheme and host only. The rest of a DSN is a public key and a project
     * path, and neither belongs in a response header.
     *
     * @return list<string>
     */
    private function sentryOrigins(): array
    {
        $origins = [];

        $configured = [
            config('security.csp.sentry_dsn'),
            config('security.csp.sentry_browser_dsn'),
        ];

        foreach ($configured as $dsn) {
            if (! is_string($dsn) || $dsn === '') {
                continue;
            }

            $parts = parse_url($dsn);

            // A malformed DSN is a configuration mistake, not a request-time
            // failure. Skipping it leaves the strict header in place, which is
            // the safe way to be wrong.
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            $origin = $parts['scheme'].'://'.$parts['host'];

            if (isset($parts['port'])) {
                $origin .= ':'.$parts['port'];
            }

            $origins[] = $origin;
        }

        return array_values(array_unique($origins));
    }
}
