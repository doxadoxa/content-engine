<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        // Normalised here, and *not* because the route needs it: Fortify's
        // ProfileInformationController lower-cases the username before it calls
        // this, exactly as the registration one does, and
        // `fortify.lowercase_usernames` is on. Through the only path that
        // reaches this today the address already arrives folded, which a test
        // that went through the route would not be able to tell.
        //
        // It is here for the reason `CreateNewUser` states for itself, which is
        // this codebase's settled position: an action implements a contract,
        // its correctness should not rest on what its caller happened to do
        // first, and the next caller may be a console command or an
        // administrative screen. What it costs is one line; what it buys is
        // that the two rules below stop depending on a controller in a package.
        //
        // Both of them do depend on it. `unique` compares with `=`, which on
        // Postgres is case-sensitive, so `Taken@Example.com` would pass against
        // an existing `taken@example.com` and store a second spelling of one
        // address. And the comparison further down reads a differently-cased
        // version of the *same* address as a change, which empties
        // `email_verified_at` and sends somebody to their inbox over a capital
        // letter.
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));

        Validator::make([...$input, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ])->validateWithBag('updateProfileInformation');

        $input['email'] = $email;

        // Changing the address always re-verifies now. The stock scaffolding
        // guarded this with `$user instanceof MustVerifyEmail`, which was a
        // real question while accounts were made by hand and stopped being one
        // when registration opened: the model declares the interface, so the
        // guard was a branch that could no longer be taken. Leaving it would
        // have read as though unverified addresses were still a possibility
        // somebody had thought about.
        if ($input['email'] !== $user->email) {
            $this->updateVerifiedUser($user, $input);

            return;
        }

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
        ])->save();
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
