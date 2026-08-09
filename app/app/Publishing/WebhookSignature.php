<?php

declare(strict_types=1);

namespace App\Publishing;

/**
 * The signature both ends compute (§1 of the contract).
 *
 * One class, used by the engine to sign and by the receiver package to verify,
 * because a contract where the two sides each implement the scheme from prose
 * is a contract that works until somebody trims a newline.
 */
final class WebhookSignature
{
    public const string ALGORITHM = 'sha256';

    /** The signed message is `{timestamp}.{raw body}` — never the body alone. */
    public static function compute(string $secret, int $timestamp, string $body): string
    {
        return self::ALGORITHM.'='.hash_hmac(self::ALGORITHM, $timestamp.'.'.$body, $secret);
    }

    /**
     * Constant-time comparison, plus the freshness window.
     *
     * The timestamp is what makes a captured request useless tomorrow: without
     * it a valid signature is valid forever, and anyone who once saw a delivery
     * can replay it whenever they like.
     */
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

        // Both directions: a clock far ahead is as suspicious as one behind.
        if (abs($now - $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(self::compute($secret, $timestamp, $body), $signature);
    }
}
