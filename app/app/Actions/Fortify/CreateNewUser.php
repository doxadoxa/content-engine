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
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // Case-insensitively, because `Alex@example.com` and
                // `alex@example.com` are one mailbox everywhere that matters
                // and the unique index would otherwise let them be two accounts
                // — which is two free trials for the price of one shift key.
                'unique:users,email',
            ],
            'password' => $this->passwordRules(),
        ], [
            'email.unique' => 'An account already exists for that address.',
        ])->validate();

        return User::create([
            'name' => trim((string) $input['name']),
            // Lower-cased on the way in for the same reason the rule above is
            // case-insensitive: the address is an identity, and an identity
            // that depends on how somebody typed it is not one.
            'email' => mb_strtolower(trim((string) $input['email'])),
            'password' => Hash::make((string) $input['password']),
        ]);
    }
}
