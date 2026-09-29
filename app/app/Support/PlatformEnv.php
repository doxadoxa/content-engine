<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Env;

/**
 * An environment variable that may still say `${SOMETHING_ELSE}`.
 *
 * `.env.example` writes `MAIL_FROM_NAME="${APP_NAME}"`, and phpdotenv expands
 * that when it reads a `.env` file. A hosting platform's own environment
 * variables are not a `.env` file: whatever was pasted into the dashboard
 * arrives verbatim. Production copied the example across, and every
 * verification mail went out from a sender literally named `${APP_NAME}` —
 * the first message a new customer ever gets from us.
 *
 * Only for the handful of variables the example writes as references. Called
 * from config files, which are the only place `env()` is read.
 */
final class PlatformEnv
{
    /**
     * The variable, with `${NAME}` references resolved against the
     * environment. A reference to something unset falls back to `$default`
     * rather than leaking the placeholder, which is the bug this exists for.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Env::get($key);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        $unresolved = false;

        $resolved = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/i', static function (array $match) use (&$unresolved): string {
            $referenced = Env::get($match[1]);

            if (! is_scalar($referenced) || (string) $referenced === '') {
                $unresolved = true;

                return '';
            }

            return (string) $referenced;
        }, $value);

        return $unresolved || $resolved === null ? $default : $resolved;
    }
}
