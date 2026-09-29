<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Affiliates\Referrals;
use App\Http\Middleware\SyncReferralConsent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Called by resources/js/lib/affiliates.ts when marketing consent is withdrawn,
 * just before it deletes Anderro's cookies — while the visitor id is still
 * there to say which referral this browser's sign-up created.
 *
 * Open to signed-out visitors on purpose: somebody who logged out and then
 * switched marketing off on the cookie policy has withdrawn as surely as
 * somebody who did it from their settings, and their renewals must stop being
 * reported all the same. The signed-in case from any other browser is
 * {@see SyncReferralConsent}.
 *
 * Always 204. What it did, or whether any account was found, is nobody's
 * business but ours.
 */
final class WithdrawReferralController extends Controller
{
    public function __invoke(Request $request, Referrals $referrals): Response
    {
        $referrals->withdrawVisitor($request);

        return response()->noContent();
    }
}
