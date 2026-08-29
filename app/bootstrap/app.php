<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureCurrentProject;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireAdministrator;
use App\Http\Middleware\RequireEntitlement;
use App\Http\Middleware\RequireProjectOwner;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SentryContext;
use App\Http\Middleware\ThrottleRegistration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Env;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'project.owner' => RequireProjectOwner::class,
            // Takes the quota it protects: `project.entitled:articles`.
            'project.entitled' => RequireEntitlement::class,
            // Who runs the service, as opposed to who runs a project.
            'admin' => RequireAdministrator::class,
        ]);

        // Take the scheme and host from X-Forwarded-*. In the container Caddy
        // speaks plain HTTP to PHP, and anything in front of it (a tunnel, a
        // load balancer) holds the certificate. Without this Laravel sees
        // `http`, builds `http://` asset URLs, and a browser on an `https://`
        // page blocks them as mixed content — the app loads unstyled.
        // Configuration is not yet bound when this bootstrap callback runs,
        // so read the narrow deployment boundary directly from the environment.
        $middleware->trustProxies(at: array_values(array_filter(array_map(
            static fn (string $proxy): string => trim($proxy),
            explode(',', (string) Env::get('TRUSTED_PROXIES', '127.0.0.1,::1')),
        ))));

        // Meta has no session and no CSRF token to present, and the POST is
        // authenticated by the HMAC over its raw body instead — see
        // App\Http\Middleware\VerifyThreadsSignature, which the route carries.
        // Narrow on purpose: this one path and nothing under it.
        $middleware->validateCsrfTokens(except: ['api/threads/webhook']);

        // Read by the inline script in app.blade.php before React mounts, so
        // the page does not flash light before switching to dark.
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            SecurityHeaders::class,
            // Fortify owns the registration route and will not throttle it —
            // see the middleware for why this is a group entry rather than a
            // route change.
            ThrottleRegistration::class,
            HandleAppearance::class,
            // Before Inertia, deliberately: Inertia\Middleware::handle
            // registers its shared data on the way *in*, so a project resolved
            // after it would not reach the page.
            EnsureCurrentProject::class,
            // After the project is resolved, not before: this reads it.
            SentryContext::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // And before route model binding, which is not the order `append` gives
        // it. Binding resolves a tenant-scoped model — a content unit, a
        // channel, a delivery — and ProjectScope fails closed, so without a
        // tenant already set the lookup finds nothing and Laravel answers 404.
        // Every detail page in the panel was unreachable by URL because of it.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureCurrentProject::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reports what Laravel decides to report — so the framework's own
        // "not worth reporting" list (validation failures, 404s, a wrong
        // password) still applies, and Sentry sees faults rather than users
        // making ordinary mistakes. No-op when the DSN is empty.
        Integration::handles($exceptions);

        // The panel's forms are Inertia, which wants a redirect with a flashed
        // error bag rather than a 422 — hence the narrowing. But the onboarding
        // wizard talks to two endpoints over fetch with `Accept: application/
        // json`, and those have to get the failure as JSON or they read an HTML
        // redirect and report nothing useful. Inertia's own requests accept
        // HTML, so they are unaffected.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
