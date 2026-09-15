<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Exceptions\SocialLoginRefused;
use App\Auth\SocialIdentities;
use App\Enums\SocialLoginProvider;
use App\Http\Controllers\Controller;
use App\Integrations\Google\GoogleOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Signing in with an account somebody already has.
 *
 * Socialite rather than a hand-rolled flow, and deliberately not the one in
 * {@see GoogleOAuth} — which exists in this codebase
 * and does look like it would fit. That one obtains a long-lived grant to read
 * a project's Search Console and Analytics: it asks for those scopes, stores a
 * refresh token, and has a whole renewal and revocation story attached. This
 * needs the opposite — one consent, three claims, nothing kept. Reusing it
 * would mean a sign-in button that asks a stranger for permission to read their
 * site's traffic, which is both a worse consent screen and a token we would
 * then be holding for no reason.
 *
 * Socialite is Laravel's own package (`laravel/socialite`), which matters here
 * for one specific thing beyond convenience: it verifies the `state` parameter
 * on the way back, and a sign-in flow that does not is a login-CSRF — an
 * attacker completes their own consent, feeds the victim the callback URL, and
 * the victim's browser ends up signed into the attacker's account. That check
 * is the reason a "just fetch the token yourself" version of this file would be
 * wrong however short it looked.
 *
 * The failure messages all land back on the sign-in screen in the session,
 * because there is nowhere else for them to go: this is a full-page browser
 * redirect from a third party, not an Inertia request with an error bag.
 */
class SocialLoginController extends Controller
{
    /** Read by the sign-in and sign-up screens. Kept here so both spell it the same. */
    public const string SESSION_ERROR = 'socialError';

    public function __construct(private readonly SocialIdentities $identities) {}

    /** Off to the provider. */
    public function redirect(SocialLoginProvider $provider): SymfonyRedirect
    {
        if (! $provider->isConfigured()) {
            // Reachable by typing the URL on an installation with no
            // credentials, where the button is not rendered at all.
            return $this->refuse($provider->label().' sign-in is not set up on this installation.');
        }

        return $this->driver($provider)->redirect();
    }

    /**
     * Back from the provider.
     *
     * Every exit from here is a redirect to a screen with a sentence on it. A
     * stack trace is the wrong answer to somebody pressing "Cancel", and an
     * unexplained bounce back to the form is the wrong answer to everything
     * else.
     */
    public function callback(Request $request, SocialLoginProvider $provider): RedirectResponse
    {
        if (! $provider->isConfigured()) {
            return $this->refuse($provider->label().' sign-in is not set up on this installation.');
        }

        if ($request->query('error') !== null) {
            // Almost always "Cancel". Not a failure, and not worth a red
            // banner or a report.
            return redirect()->route('login');
        }

        try {
            $account = $this->driver($provider)->user();
        } catch (InvalidStateException) {
            // The session lost its state, or the callback did not start here.
            // Indistinguishable from the outside and the same advice either
            // way; a stale tab after a session expiry is by far the common one.
            return $this->refuse('That sign-in did not come from here, or it took too long. Try again.');
        } catch (Throwable $e) {
            // The provider was unreachable, or answered with something we could
            // not read. Reported, because unlike the two above it is our
            // problem — but never with the query string, which carries the
            // authorisation code.
            Log::warning('Social sign-in failed at the provider.', [
                'provider' => $provider->value,
                'exception' => $e::class,
            ]);

            return $this->refuse('We could not reach '.$provider->label().' just now. Try again in a moment.');
        }

        try {
            $user = $this->identities->resolve($provider, $account);
        } catch (SocialLoginRefused $e) {
            // Written for the person reading it — see the exception.
            return $this->refuse($e->getMessage());
        }

        // Remembered, unlike the email form where it is a checkbox somebody
        // ticks. There is no checkbox to offer on this path — the last screen
        // before here was Google's, and it has no room for one — and the
        // expectation the button sets is that pressing it is the last time you
        // think about signing in. The session cookie alone would expire this
        // in two hours and send them back through a consent screen they have
        // already given.
        Auth::login($user, remember: true);

        // Against session fixation: whatever id the browser arrived holding is
        // not the one it leaves authenticated with. Fortify does this on its
        // own login path and this one has to do it for itself.
        $request->session()->regenerate();

        // `intended`, so somebody who was sent to the sign-in screen by trying
        // to open a page lands on that page rather than on the dashboard.
        return redirect()->intended((string) config('fortify.home', '/dashboard'));
    }

    /**
     * Socialite's Google driver, pointed at *this* callback.
     *
     * The redirect URI is not read from `services.google.redirect`: that one
     * belongs to the project integration and Google matches redirect URIs as
     * exact strings, so the two flows need two registered URIs. Built from the
     * route by default — one fewer environment variable to keep in step with
     * the route table — with `services.google.auth_redirect` as the override
     * for a deployment whose public URL is not the one `route()` would build.
     */
    private function driver(SocialLoginProvider $provider): AbstractProvider
    {
        $configured = config('services.'.$provider->value.'.auth_redirect');

        $redirect = is_string($configured) && $configured !== ''
            ? $configured
            : route('oauth.callback', ['provider' => $provider->value]);

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider->value);

        return $driver->redirectUrl($redirect);
    }

    /** Back to the sign-in screen, with something to read. */
    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->with(self::SESSION_ERROR, $message);
    }
}
