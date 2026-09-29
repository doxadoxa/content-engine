<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Affiliates\Referrals;
use App\Http\Controllers\WithdrawReferralController;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the server learns what a signed-in, referred customer's browser now
 * says about marketing — see {@see Referrals::syncConsent()}.
 *
 * A middleware rather than an endpoint the banner posts to, because the answer
 * is a cookie the browser sends anyway, and it covers the case the endpoint
 * cannot: a refusal from a browser the sign-up did not come from, where there
 * is no visitor id to match on but there is a session. The signed-out case is
 * {@see WithdrawReferralController}.
 *
 * Page loads only. Withdrawing reloads the page, so that request is where a
 * refusal first arrives, and running on every JSON call and form post as well
 * would be a query each for nothing.
 */
final class SyncReferralConsent
{
    public function __construct(private readonly Referrals $referrals) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $request->isMethod('GET')) {
            $this->referrals->syncConsent($user, $request);
        }

        /** @var Response */
        return $next($request);
    }
}
