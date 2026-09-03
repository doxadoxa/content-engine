<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * Email a first password to somebody who has never had one.
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
     * The answer is the same either way, and deliberately vague about whether
     * anything was sent — the broker throttles per address (see
     * `config/auth.php`), and a screen that said "already sent one, wait" would
     * be reporting on somebody else's inbox as readily as on your own.
     */
    public function sendLink(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasPassword()) {
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', 'If this account can take a password, a link to set one is on its way to '.$user->email.'.');
    }
}
