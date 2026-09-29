<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Affiliates\Referrals;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;

/**
 * The sign-up form's way into {@see Referrals::signedUp()}.
 *
 * Fortify owns the registration route and fires `Registered` from it, so this
 * is the seam rather than the action that creates the user — which is also
 * called from places that are not somebody at a browser. Signing up with
 * Google does not fire this event (see {@see SocialLoginController}, which
 * reports its own), so the two paths cannot double up.
 */
final class ReportRegistrationToAffiliates
{
    public function __construct(private readonly Referrals $referrals, private readonly Request $request) {}

    public function handle(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->referrals->signedUp($event->user, $this->request);
        }
    }
}
