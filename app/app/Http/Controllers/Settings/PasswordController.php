<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/password', [
            // Whether this account has a password at all. One that has only
            // ever arrived through Google does not, and the screen it needs is
            // a different screen — not the change form with a field removed.
            // See {@see UpdateUserPassword}, which still requires the current
            // password from everybody, and `sendLink()` below for what these
            // accounts get instead.
            'hasPassword' => $user->hasPassword(),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Email a first password to somebody who has never had one, and end the
     * session on the way out.
     *
     * The ordinary reset flow, aimed at the address already on the account. It
     * exists as its own route because Fortify's `/forgot-password` is behind
     * `guest`, and the person asking for this is signed in — through Google,
     * which is the whole reason they have no password.
     *
     * Why an email at all, when they are already authenticated. Because a
     * session is not evidence of anything durable: it can be borrowed, and a
     * password minted from a borrowed one survives that session being revoked.
     * The link proves the inbox, which is the same proof this application
     * accepts from anybody who has forgotten their password.
     *
     * **And why it signs them out.** The link goes to `password.reset`, which
     * Fortify puts behind `guest` — both the form and the submission. Sending
     * it to somebody who stays signed in produces a link that bounces them to
     * the dashboard with no explanation when they click it in the same browser,
     * which is the whole advertised flow failing silently. Ending the session
     * here is the smaller of the two honest answers; the other is a second
     * reset form of our own, behind `auth`, duplicating Fortify's.
     *
     * It also costs nothing worth keeping. Signing back in is one press of the
     * Google button, and a session that was not theirs to begin with is exactly
     * the thing this ends.
     */
    public function sendLink(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasPassword()) {
            // A stale tab: this screen is only offered to accounts without one,
            // and the form is the way to change a password that exists. Nothing
            // sent, and no logout for somebody who did not ask for one.
            return back();
        }

        $email = $user->email;

        Password::broker()->sendResetLink(['email' => $email]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with(
            'status',
            'A link to set a password is on its way to '.$email.'. We signed you out here, because that link only opens when you are.'
        );
    }
}
