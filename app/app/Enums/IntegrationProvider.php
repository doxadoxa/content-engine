<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An outside account a project is connected to.
 *
 * The column is an enum rather than a boolean `google_connected` because the
 * second provider is a row, not a migration on every project — and because what
 * is stored per connection (tokens, scopes, chosen properties) is the same
 * shape whoever granted it.
 */
enum IntegrationProvider: string
{
    /** Search Console and GA4, granted together in one consent screen. */
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
        };
    }
}
