<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
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

        Fortify::registerView(fn () => Inertia::render('auth/register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'trialDays' => (int) config('billing.trial.days', 3),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));
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
