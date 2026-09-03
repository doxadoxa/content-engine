<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and update the user's password.
     *
     * The current one is required only from somebody who has one. An account
     * that has only ever arrived through Google has a null password — see the
     * `oauth_identities` migration — and asking it to prove a password it does
     * not have is a form that can never be submitted, which would leave signing
     * in with Google as the only way in forever.
     *
     * Dropping the check for those accounts costs nothing, because the thing it
     * defends is already true of them: the point of `current_password` is that
     * a borrowed session cannot silently change the credential, and here there
     * is no credential to change — only one to add, and the session was
     * obtained from the provider a moment ago.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $rules = ['password' => $this->passwordRules()];

        if ($user->hasPassword()) {
            $rules['current_password'] = ['required', 'string', 'current_password:web'];
        }

        Validator::make($input, $rules, [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
