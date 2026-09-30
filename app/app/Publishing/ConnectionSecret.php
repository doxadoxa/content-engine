<?php

declare(strict_types=1);

namespace App\Publishing;

use Illuminate\Support\Str;

/**
 * The shared secret a webhook connection signs with, made by Avyo.
 *
 * The connect form used to ask the owner to invent one, which nobody who is
 * not a developer knows how to do; the onboarding wizard made one and never
 * showed it to anybody, so the developer could not check a signature. Both
 * now come from here, and the owner reads it back on the website page.
 */
final class ConnectionSecret
{
    public const int LENGTH = 48;

    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }
}
