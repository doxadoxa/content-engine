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
}
