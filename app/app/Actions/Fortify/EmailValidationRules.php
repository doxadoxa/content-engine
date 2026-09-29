<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

trait EmailValidationRules
{
    /**
     * The address somebody is asking us to write to.
     *
     * `email` alone only checks the shape. `courlty.cloud` has a perfectly good
     * shape, so a signup with that typo succeeded, the verification mail
     * bounced, and the only screen that person could reach told them to go and
     * read it. `dns` asks whether the domain accepts mail at all, which turns
     * that into an error on the form they are still looking at.
     *
     * Switchable because the lookup refuses reserved names like `.test` without
     * asking anybody, and the suite and local development both sign up with
     * them. See `auth.check_email_domains`.
     *
     * @return array<int, string>
     */
    protected function emailRules(): array
    {
        return [
            'required',
            'string',
            config('auth.check_email_domains', true) ? 'email:rfc,dns' : 'email:rfc',
            'max:255',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function emailMessages(): array
    {
        return [
            'email.email' => 'That address can’t receive email. Check the spelling, especially after the @.',
        ];
    }
}
