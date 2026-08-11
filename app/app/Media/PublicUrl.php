<?php

declare(strict_types=1);

namespace App\Media;

use App\Pipelines\Steps\Generation\IllustrateDraft;

/**
 * Whether an outside service could actually fetch this URL.
 *
 * Loopback and private ranges cannot be reached from anybody else's network,
 * and handing one to an image provider fails the whole call on a connection
 * refused. On a developer's machine the disk URL is `localhost`, so every
 * reference picture would take the generation down with it — which is why the
 * callers pass references only when this says yes, and lose the visual
 * consistency there rather than losing the images.
 *
 * Lifted out of {@see IllustrateDraft}, where it was private, when
 * {@see SocialImage} needed the same answer. Two copies of a network-safety
 * predicate is one copy that gets fixed.
 */
final class PublicUrl
{
    public static function isFetchable(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            // A name that does not resolve here may still resolve out there.
            return true;
        }

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }
}
