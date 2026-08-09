<?php

declare(strict_types=1);

namespace Persistance\EngineReceiver;

/**
 * The signature scheme, receiver side.
 *
 * A deliberate copy of the engine's class rather than a shared dependency: a
 * receiver should be installable on a site that knows nothing about the engine
 * beyond this package, and pulling the whole engine in to verify sixty bytes of
 * HMAC would be the wrong trade. The scheme is frozen in the contract, which is
 * what makes duplicating it safe.
 */
final class WebhookSignature
{
    public const string ALGORITHM = 'sha256';

    public static function compute(string $secret, int $timestamp, string $body): string
    {
        return self::ALGORITHM.'='.hash_hmac(self::ALGORITHM, $timestamp.'.'.$body, $secret);
    }

    public static function verify(
        string $secret,
        string $signature,
        int|string|null $timestamp,
        string $body,
        int $tolerance = 300,
        ?int $now = null,
    ): bool {
        if (! is_numeric($timestamp)) {
            return false;
        }

        $timestamp = (int) $timestamp;
        $now ??= time();

        if (abs($now - $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(self::compute($secret, $timestamp, $body), $signature);
    }
}
