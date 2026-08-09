<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An outside account a project is connected to.
 *
 * The column is an enum rather than a boolean `google_connected` because the
 * second provider is a row, not a migration on every project — and because what
 * is stored per connection (tokens, scopes, chosen properties) is the same
 * shape whoever granted it. The second provider has now arrived and the bet
 * held: Threads needed no schema change at all.
 */
enum IntegrationProvider: string
{
    /** Search Console and GA4, granted together in one consent screen. */
    case Google = 'google';

    /**
     * The Threads publishing and listening credential (§9).
     *
     * A grant that is *written* through, unlike Google's, which is only read
     * from. It lives here rather than on the channel because §9 says so: the
     * channel holds the target and the toggles, and the token belongs beside
     * the one renewal mechanism this codebase already has.
     */
    case Threads = 'threads';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Threads => 'Threads',
        };
    }
}
