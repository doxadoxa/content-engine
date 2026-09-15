<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\TrialEligibility;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
/**
 * `MustVerifyEmail`, because an unverified account is an open tab.
 *
 * The engine spends real money at a provider on every trial — measured, about
 * $2.83 of model and image calls — so the address has to be proved before the
 * wizard is allowed to start anything. It is the cheapest of the three checks
 * in {@see TrialEligibility} and the one that makes the other
 * two worth having.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /**
     * The account is the Stripe customer; a *project* is the subscription.
     *
     * That split is the whole billing shape. One saved card, one customer
     * record, and one Cashier "named" subscription per project — named after
     * the project's ULID — so somebody with four sites manages all four from a
     * single Billing Portal instead of entering the same card four times.
     *
     * Which project a subscription is *for* is not asked of this trait: it is
     * on {@see ProjectSubscription}, which is the row entitlement reads and the
     * only one the engine consults. Cashier's tables are the receipt.
     */
    use Billable;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Membership in at least one project is what makes an account usable — one
     * with none would land on a dashboard with no project to work in and no way
     * to get one.
     *
     * §10 of the spec used to add that an account exists only because somebody
     * created it, and that stopped being true when the service grew a trial:
     * registration is public, and the wizard that follows it is what turns a
     * new account into a usable one.
     */
    public function belongsToAnyProject(): bool
    {
        return $this->projects()->exists();
    }

    /**
     * The outside providers this account can arrive through.
     *
     * A `HasMany` although one provider is supported today and the schema
     * allows one identity per provider: the plural is what the second provider
     * needs, and the singular would have to be unpicked from every caller the
     * day it arrives.
     *
     * @return HasMany<OauthIdentity, $this>
     */
    public function oauthIdentities(): HasMany
    {
        return $this->hasMany(OauthIdentity::class);
    }

    /**
     * Whether this account can be signed into with a password at all.
     *
     * False for somebody who has only ever come through Google, and the
     * settings screens have to ask before offering them a form that starts by
     * demanding a current password they do not have. Reads the raw attribute
     * rather than the `hashed` cast, which would happily hash a null on the way
     * past and answer the wrong question.
     */
    public function hasPassword(): bool
    {
        return is_string($this->getRawOriginal('password')) && $this->getRawOriginal('password') !== '';
    }

    /**
     * Both of these exist only to swap the framework's notification for the
     * queued subclass. Everything else about them — the signed URL, the token,
     * the throttling on the routes that trigger them — is unchanged.
     *
     * Overridden here rather than in a service provider because this is where
     * the framework looks: `MustVerifyEmail` and `CanResetPassword` are traits
     * on the model, and a listener or a `toMailUsing` callback could change the
     * mail's contents but not the fact that it is sent inline.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    /**
     * Untyped `$token` on purpose: `Illuminate\Contracts\Auth\CanResetPassword`
     * declares it without a type, and narrowing a parameter in an override is
     * a fatal error rather than a stricter signature.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPassword($token));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }
}
