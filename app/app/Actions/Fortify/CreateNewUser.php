<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Billing\TrialEligibility;
use App\Http\Controllers\OnboardingController;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * A new account, and nothing else.
 *
 * Deliberately no project and no subscription. A project is what the onboarding
 * wizard produces — a site that was read, a brief that was confirmed — and the
 * free window is stamped when the engine *starts*, not when the account is
 * made: somebody who signs up on Friday and finishes the wizard on Monday has
 * not had a trial over the weekend.
 *
 * See {@see OnboardingController::launch()} for where the
 * trial actually begins, and {@see TrialEligibility} for what has
 * to be true first.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        // Normalised before it is validated, not after.
        //
        // `unique:users,email` compares with `=`, which on Postgres is
        // case-sensitive — so `Alex@example.com` would pass the rule against an
        // existing `alex@example.com`, be lower-cased on the way to the insert,
        // and die on the unique index as a 500 rather than as the validation
        // message this action already writes.
        //
        // Fortify happens to lower-case the username before it calls this
        // (`fortify.lowercase_usernames`), so that sequence does not occur
        // today through the registration route. It is done here anyway, because
        // this action's correctness should not rest on what its caller happened
        // to do first — it implements a contract, and the next caller may be a
        // console command or an administrative screen.
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));

        Validator::make([...$input, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ], [
            'email.unique' => 'An account already exists for that address.',
        ])->validate();

        return User::create([
            'name' => trim((string) $input['name']),
            // The address is an identity, and an identity that depends on how
            // somebody typed it is not one.
            'email' => $email,
            'password' => Hash::make((string) $input['password']),
        ]);
    }
}
