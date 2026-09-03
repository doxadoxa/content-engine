<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/password', [
            // Whether this account has a password at all. One that has only
            // ever arrived through Google does not, so the form asks it to set
            // a first one rather than to prove a current one it cannot — see
            // App\Actions\Fortify\UpdateUserPassword, which drops the same
            // field for the same accounts. The screen and the rule have to
            // agree, or the form asks for something the validator ignores.
            'hasPassword' => $user->hasPassword(),
        ]);
    }
}
