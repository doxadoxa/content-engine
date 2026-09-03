<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Enums\SocialLoginProvider;
use App\Http\Controllers\Auth\SocialLoginController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        /*
         * These two were missing, and their absence was not quiet in the way a
         * missing binding usually is.
         *
         * `Fortify::*Using()` is the *only* thing that binds these contracts —
         * Fortify's own provider binds none of them — and both features are
         * enabled in `config/fortify.php`. So the routes existed, the screens
         * rendered, and submitting either one resolved a contract with no
         * implementation: `Target [UpdatesUserPasswords] is not instantiable`,
         * a 500 on every attempt to change a password or a name. The actions
         * themselves were written and correct; nothing ever asked for them.
         *
         * Found while adding Google sign-in, because an account that arrives
         * through Google has no password and the only way for it to get one is
         * the screen this had broken.
         */
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);

        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Fortify owns the auth routes; these say what to render on them.
     *
     * Inertia pages rather than Blade, so the sign-in screen is built from the
     * same components as the rest of the app — otherwise it is the one screen
     * that drifts, and it is the first one anybody sees.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            ...$this->socialProps($request),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->string('email')->toString(),
            'token' => (string) $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'trialDays' => (int) config('billing.trial.days', 3),
            ...$this->socialProps($request),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));
    }

    /**
     * What the two entry screens need to know about signing in with somebody
     * else's account.
     *
     * The same two props on both, from one place, because they are one
     * decision: which providers this installation actually has credentials for,
     * and what to say if coming back from one of them went wrong. An
     * installation with no Google client renders no Google button — rather than
     * a button that leads to Google's own error page, which is a dead end with
     * our name on it.
     *
     * @return array<string, mixed>
     */
    private function socialProps(Request $request): array
    {
        $providers = [];

        foreach (SocialLoginProvider::cases() as $provider) {
            if ($provider->isConfigured()) {
                $providers[] = ['key' => $provider->value, 'label' => $provider->label()];
            }
        }

        return [
            'socialProviders' => $providers,
            'socialError' => $request->session()->get(SocialLoginController::SESSION_ERROR),
        ];
    }

    private function configureRateLimiting(): void
    {
        // Keyed on the submitted address as well as the IP: keying on IP alone
        // makes one office share a bucket, and keying on the address alone lets
        // anyone lock a known operator out by failing five logins as them.
        RateLimiter::for('login', function (Request $request): Limit {
            $key = Str::transliterate(
                Str::lower($request->string(Fortify::username())->toString()).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($key);
        });

        // Registration is public now, and an account is the first step towards
        // a trial that costs us real money at a provider. By IP alone, because
        // there is no address to key on yet that an abuser does not choose.
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perHour(5)->by((string) $request->ip()));

    }
}
