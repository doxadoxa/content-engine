<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Models\Channel;
use App\Models\WebhookDelivery;

/**
 * The identity of a website connection as a test sees it: where it sends,
 * and with which credentials.
 *
 * Stored on each test when it is sent, and compared when its answer comes
 * back. A test that finishes after the address or secret has changed was a
 * test of a connection that no longer exists, and its answer — pass or fail
 * — must not be written onto the new one.
 *
 * The secret is taken as stored ciphertext: the fingerprint never holds the
 * secret, and a new secret is new ciphertext even when it is the same text.
 * Not {@see Pages\PageReceiverClient::fingerprint()}, which leaves out the
 * webhook address and whose values existing page updates already carry.
 */
final class ConnectionFingerprint
{
    public static function of(Channel $channel): string
    {
        $config = $channel->config;

        return hash('sha256', json_encode([
            $channel->type->value,
            self::value($config['endpoint'] ?? null),
            self::value($config['page_receiver_base'] ?? null),
            self::value($config['username'] ?? null),
            $channel->getRawOriginal('secret'),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Whether a test's answer is still about the connection as it is now.
     * Tests sent before fingerprints were stored are taken at their word.
     */
    public static function stillCurrent(WebhookDelivery $delivery, Channel $channel): bool
    {
        $sent = $delivery->getAttribute('connection_fingerprint');

        return ! is_string($sent) || $sent === '' || hash_equals($sent, self::of($channel));
    }

    private static function value(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
