<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Affiliates\Referrals;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the server learns that a referred customer has withdrawn marketing
 * consent — see {@see Referrals::forgetIfWithdrawn()}.
 *
 * A middleware rather than an endpoint the banner posts to, because the answer
 * is a cookie the browser sends anyway, and an endpoint is one more request
 * that can fail between somebody saying no and us listening. Costs nothing for
 * everybody else: the check stops at a column already loaded with the user.
 */
final class ForgetWithdrawnReferral
{
    public function __construct(private readonly Referrals $referrals) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->referrals->forgetIfWithdrawn($user, $request);
        }

        /** @var Response */
        return $next($request);
    }
}
