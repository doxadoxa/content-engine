<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * A ceiling on how fast accounts can be made.
 *
 * Fortify owns the registration route and reads `fortify.limiters` for login,
 * two-factor, passkeys and verification — but not for registration, whose route
 * carries only `guest:`. So the limiter this application defines has nothing
 * that would ever call it, and signing up was completely unthrottled. A limiter
 * defined and never referenced is worse than none, because it reads as a
 * control that exists.
 *
 * A middleware in the group rather than a route change, because the route is
 * not ours to change: registering a second `POST /register` in front of
 * Fortify's would work by matching order and leave two routes with one name,
 * and mutating theirs after boot depends on where this provider happens to sit
 * in the list.
 *
 * Narrow on purpose: one method, one path, and everything else passes straight
 * through.
 *
 * Why this matters more than an ordinary signup form. An account is the first
 * step towards a free trial, and every trial spends real model and image calls
 * at a provider — measured, about $2.83. Unthrottled registration is an open
 * tab someone else is holding.
 */
final class ThrottleRegistration
{
    /** By IP alone, because there is no address to key on that an abuser does not choose. */
    private const string LIMITER = 'register';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('register.store')) {
            return $next($request);
        }

        $key = self::LIMITER.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('fortify.registration_attempts', 5))) {
            abort(429, 'Too many accounts have been created from here. Try again later.');
        }

        RateLimiter::hit($key, 3600);

        return $next($request);
    }
}
