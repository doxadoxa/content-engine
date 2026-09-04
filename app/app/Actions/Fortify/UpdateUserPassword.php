<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Http\Controllers\Settings\PasswordController;
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
     * The current one is always required, including from an account that has
     * none — which is not a contradiction, it is a closed door: that account
     * cannot set a password here at all, and asks for an emailed link instead
     * (see {@see PasswordController::sendLink()}).
     *
     * An earlier version of this dropped the rule for those accounts, so that
     * the screen would work for somebody who had only ever arrived through
     * Google. It made a session, on its own, enough to mint a permanent
     * credential — which turns a borrowed session into permanent access that
     * outlives the session being revoked. The emailed link proves the inbox
     * instead, and that is the same proof this application already accepts from
     * anybody who has forgotten their password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
