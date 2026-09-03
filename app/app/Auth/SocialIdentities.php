<?php

declare(strict_types=1);

namespace App\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Auth\Exceptions\SocialLoginRefused;
use App\Enums\SocialLoginProvider;
use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Turning "Google says this is Alex" into an account on this installation.
 *
 * Three cases, in this order, and the order is the security property:
 *
 * 1. **We have seen this identity before.** The provider's subject is in
 *    `oauth_identities`, so there is nothing to decide — sign in as its owner.
 *    First because it is the only case that needs no trust in the address at
 *    all: whoever holds the Google account is who they were last time.
 * 2. **There is an account under that address.** Link the identity to it. This
 *    is the branch that can hand somebody else's account away, so it is the one
 *    that insists the provider has *verified* the address (below).
 * 3. **Nobody.** Make an account. No project, no subscription — exactly what
 *    {@see CreateNewUser} makes, because arriving through
 *    Google should leave a person in the same place as arriving through the
 *    form: at the start of the wizard.
 *
 * **Why the verified check is not optional.** An OAuth provider that lets
 * anybody claim an unverified address turns case 2 into a takeover: sign up
 * there as `alex@some-company.com`, come here, and be handed Alex's account.
 * Google verifies addresses on its own accounts and says so in `email_verified`
 * — so the check costs nothing today and is the only thing standing between
 * this code and that attack the day a second provider is added. An unverified
 * address is refused outright rather than routed into case 3, because creating
 * a second account under an address that already has one would collide with the
 * unique index a moment later anyway.
 */
final class SocialIdentities
{
    /**
     * @throws SocialLoginRefused when the grant cannot safely become an account
     */
    public function resolve(SocialLoginProvider $provider, SocialiteUser $account): User
    {
        $subject = trim((string) $account->getId());

        if ($subject === '') {
            // Nothing to key on. Not a case worth a friendly explanation
            // because it cannot happen to an honest browser: `sub` is part of
            // what the provider is for.
            throw new SocialLoginRefused($provider->label().' did not tell us who you are. Try again.');
        }

        // The same normalisation CreateNewUser applies, for the same reason:
        // `unique:users,email` and Postgres `=` are case-sensitive, so an
        // address that differs only in case would be a second account rather
        // than a match.
        $email = mb_strtolower(trim((string) $account->getEmail()));

        return DB::transaction(function () use ($provider, $account, $subject, $email): User {
            $identity = OauthIdentity::query()
                ->where('provider', $provider)
                ->where('provider_subject', $subject)
                ->first();

            if ($identity !== null) {
                // Recorded, not keyed on: people change their address at the
                // provider, and this row should say what it is now rather than
                // what it was the day they linked.
                if ($email !== '' && $identity->email !== $email) {
                    $identity->forceFill(['email' => $email])->save();
                }

                return $identity->user()->firstOrFail();
            }

            if ($email === '') {
                throw new SocialLoginRefused(
                    'Your '.$provider->label().' account did not share an email address, so we cannot sign you in with it.'
                );
            }

            if (! $this->addressIsVerified($account)) {
                throw new SocialLoginRefused(
                    $provider->label().' has not verified that address. Verify it there, or sign in with your email and password.'
                );
            }

            $user = User::query()->where('email', $email)->first()
                ?? $this->register($account, $email);

            // The address is proved by the provider, so an account that came in
            // this way is verified on arrival — and one that already existed
            // and had not verified is verified now. It is the same proof the
            // emailed link asks for, obtained a shorter way, and leaving it
            // unset would send somebody to check an inbox for an address they
            // just demonstrated they control.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            // `updateOrCreate` rather than `create`, because the account may
            // already hold an identity for this provider under a *different*
            // subject — a Google account deleted and made again keeps the
            // address and gets a new `sub`. A plain insert would hit the
            // `(user_id, provider)` unique index and turn that into a 500 on
            // the sign-in screen. Replacing it is the same decision that was
            // made when the first one was linked, and made on the same
            // evidence: a verified address that matches this account's.
            $user->oauthIdentities()->updateOrCreate(
                ['provider' => $provider],
                ['provider_subject' => $subject, 'email' => $email],
            );

            return $user;
        });
    }

    /**
     * A brand new account, with no password.
     *
     * Null rather than a random hash. A hash nobody knows is still a credential
     * that exists — one more thing to reason about in a breach — and the
     * framework already treats an empty one as "cannot sign in this way". If
     * they want a password later, the reset flow issues one against a verified
     * address, which is a better path to it than one invented here.
     */
    private function register(SocialiteUser $account, string $email): User
    {
        $name = trim((string) $account->getName());

        if ($name === '') {
            $name = trim((string) $account->getNickname());
        }

        if ($name === '') {
            // Something addressable rather than an empty greeting on every
            // screen. They can change it in settings.
            $name = Str::of($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->trim()->toString();
        }

        return User::create([
            'name' => Str::limit($name, 255, ''),
            'email' => $email,
            'password' => null,
        ]);
    }

    /**
     * Whether the provider vouches for the address.
     *
     * Read off the raw payload rather than a mapped property, because
     * Socialite's own `User` contract has no place for it: the interface is
     * five getters and none of them is "and do they own this address". Only the
     * abstract class every shipped driver extends keeps the untouched payload,
     * so the check is guarded by an `instanceof` — and a driver that somehow
     * returns a bare contract implementation is treated as *not* vouching,
     * because the alternative is a verification check that silently passes
     * whenever it cannot be made.
     *
     * Both spellings, because Google's own payload carries `email_verified` and
     * Socialite copies it to `verified_email` for compatibility with its older
     * releases; a provider added later may map only one of them. Anything that
     * is not an explicit yes is a no.
     */
    private function addressIsVerified(SocialiteUser $account): bool
    {
        if (! $account instanceof AbstractUser) {
            return false;
        }

        $raw = $account->getRaw();

        foreach (['email_verified', 'verified_email'] as $key) {
            $value = $raw[$key] ?? null;

            if ($value === true || $value === 'true' || $value === 1 || $value === '1') {
                return true;
            }
        }

        return false;
    }
}
