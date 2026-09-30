<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the link in the verification mail lands.
 *
 * Into the wizard, for somebody who has nothing yet: they signed up to set up
 * a business, and proving their address was the step in the way of it. Home
 * for everybody else — somebody re-proving a changed address already has work
 * to get back to, and a wizard for a second project is not it.
 */
final class VerifyEmailResponse implements VerifyEmailResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $user = $request->user();

        $next = $user instanceof User && ! ProjectManager::live($user)->exists()
            ? route('onboarding.show', absolute: false)
            : route('home.index', absolute: false);

        return redirect()->intended($next.'?verified=1');
    }
}
