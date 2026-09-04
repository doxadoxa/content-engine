<?php

declare(strict_types=1);

namespace App\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Auth\Exceptions\SocialLoginRefused;
use App\Enums\SocialLoginProvider;
use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * **Why case 2 asks for more than `email_verified`.** The obvious attack is a
 * provider that lets anybody claim an unverified address: sign up there as
 * `alex@some-company.com`, come here, be handed Alex's account. `email_verified`
 * stops that — and it is not enough on its own, which an earlier version of this
 * file got wrong. For a consumer Google account on a third-party address, that
 * flag means Google verified the address *when the account was created*; it is
 * not a claim that the account still controls it. Company addresses get
 * reassigned, and the ex-colleague keeps the Google account. That is the same
 * takeover with a slower fuse.
 *
 * So linking to an account that already exists asks whether Google is
 * *authoritative* for the address — a Gmail one, or a Workspace domain it
 * asserts in `hd` — and refuses otherwise. See {@see self::ownsAddress()}, which
 * is also what decides whether arriving this way counts as proving the address.
 *
 * Not airtight, and worth saying which part is not: a Workspace domain can be
 * bought by somebody else after a company folds, and `hd` would then be
 * asserted for its new owner. Case 1 is the branch with no such caveat, because
 * the subject is the account rather than a name for it.
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

        try {
            return $this->link($provider, $account, $subject, $email);
        } catch (UniqueConstraintViolationException) {
            // Two callbacks for the same brand-new account, close enough
            // together that both transactions looked and found nothing — two
            // tabs, or a double-tapped button. On Postgres's default isolation
            // the second insert is the one that loses, and it loses with a
            // driver exception rather than a sign-in.
            //
            // Retried once, not looped: whatever won has committed by now, so
            // the second pass takes case 1 or case 2 and finds it. A second
            // failure is not a race any more and should surface.
            return $this->link($provider, $account, $subject, $email);
        }
    }

    /**
     * @throws SocialLoginRefused
     * @throws UniqueConstraintViolationException on a lost race — see the caller
     */
    private function link(SocialLoginProvider $provider, SocialiteUser $account, string $subject, string $email): User
    {
        $registered = false;

        $user = DB::transaction(function () use ($provider, $account, $subject, $email, &$registered): User {
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

            $owns = $this->ownsAddress($provider, $account, $email);
            $user = User::query()->where('email', $email)->first();

            if ($user !== null && ! $owns) {
                // Case 2, without the evidence case 2 needs. Refused rather
                // than linked, and rather than quietly making a second account
                // — the address is unique, so there is no second account to
                // make.
                //
                // This does tell somebody that an account exists under an
                // address they can prove a provider once verified for them,
                // which is a narrow enumeration oracle and the price of the
                // refusal. Closing that too means emailing a confirmation link
                // and answering identically either way, which is a flow with a
                // route and a screen and is not built here.
                throw new SocialLoginRefused(
                    'There is already a password on this account, and '.$provider->label().' cannot prove that address is yours. Sign in with your password, or reset it.'
                );
            }

            if ($user === null) {
                $user = $this->register($account, $email);
                $registered = true;
            }

            // Verified on arrival only where the provider is authoritative:
            // then this is the same proof the emailed link asks for, obtained a
            // shorter way, and sending somebody to an inbox for an address they
            // just demonstrated they control would be asking twice. Where it is
            // not, the address is unproved and the ordinary verification mail
            // is what proves it — see the caller.
            if ($owns && $user->email_verified_at === null) {
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

        // After the transaction, not inside it. The notification is queued, and
        // a job picked up before the commit would look for a user that is not
        // there yet.
        if ($registered && $user->email_verified_at === null) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
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
     * Whether the provider is authoritative for the address, as opposed to
     * merely having checked it once.
     *
     * Google's `email_verified` is necessary and not sufficient — see the class
     * note. What makes it sufficient is Google *being* the mail provider:
     *
     * - a `gmail.com` / `googlemail.com` address, which only Google issues; or
     * - a Workspace domain, which Google asserts in `hd` and which has to match
     *   the address rather than merely be present. A consumer account with a
     *   `hd` for some other domain would otherwise vouch for an address on a
     *   domain it has nothing to do with.
     *
     * A `match` on the provider rather than a method on the enum, because what
     * counts as authoritative is a fact about each provider's payload — `hd` is
     * Google's word — and the second provider will answer this differently or
     * not be able to answer it at all. One that cannot returns false and gets
     * case 1 and case 3 only, which is the safe half of this class.
     */
    private function ownsAddress(SocialLoginProvider $provider, SocialiteUser $account, string $email): bool
    {
        if (! $this->addressIsVerified($account)) {
            return false;
        }

        $domain = mb_strtolower(Str::after($email, '@'));

        return match ($provider) {
            SocialLoginProvider::Google => in_array($domain, ['gmail.com', 'googlemail.com'], true)
                || $this->hostedDomain($account) === $domain,
        };
    }

    /** The Workspace domain Google says the account belongs to, if any. */
    private function hostedDomain(SocialiteUser $account): ?string
    {
        if (! $account instanceof AbstractUser) {
            return null;
        }

        $hd = $account->getRaw()['hd'] ?? null;

        return is_string($hd) && $hd !== '' ? mb_strtolower($hd) : null;
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
