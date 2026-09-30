<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a new account goes: to its inbox, first.
 *
 * Fortify sends everybody home, and home for an account with no project is a
 * "Set up your business" button — which led straight into the `verified` wall
 * around the wizard. So the first thing a new customer did was start setting
 * up, and the second was be told to stop and read their email. Asking first
 * is the same check, in the order it is actually going to happen.
 *
 * Not `intended`: an address nobody has proved yet cannot reach anything worth
 * returning to, and every such URL would bounce here anyway.
 */
final class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        $user = $request->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(Fortify::redirects('register'));
    }
}
