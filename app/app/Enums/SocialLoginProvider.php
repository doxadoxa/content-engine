<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A provider somebody can *sign in* with.
 *
 * Separate from {@see IntegrationProvider}, which is what a *project* is
 * connected to, and the two must not be merged however similar the words look.
 * That one is a grant to read somebody's data, held per project, revocable
 * without anybody losing access to this application. This one is an identity:
 * revoking it locks a person out. They also happen to differ on Google — the
 * integration asks for Search Console and Analytics scopes and stores refresh
 * tokens; this asks for a name and an address and stores neither.
 */
enum SocialLoginProvider: string
{
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
        };
    }

    /**
     * Whether this installation has credentials for the provider.
     *
     * Consulted before the button is rendered and again before the redirect is
     * built, because a button that leads to a Google error page is worse than
     * no button — and the second check is not redundant: the first one shipped
     * with the page, and configuration can be emptied while somebody has it
     * open.
     */
    public function isConfigured(): bool
    {
        $config = config('services.'.$this->value);

        return is_array($config)
            && is_string($config['client_id'] ?? null) && $config['client_id'] !== ''
            && is_string($config['client_secret'] ?? null) && $config['client_secret'] !== '';
    }
}
