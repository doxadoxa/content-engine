<?php

declare(strict_types=1);

namespace App\Pages;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/** Normalisation does not grant permission to fetch: validate every network target separately. */
final class PageUrl
{
    public static function normalize(string $url): string
    {
        $uri = new Uri(trim($url));
        $query = [];
        foreach (explode('&', $uri->getQuery()) as $part) {
            $key = strtolower(rawurldecode(explode('=', $part, 2)[0]));
            if ($part !== '' && ! str_starts_with($key, 'utm_') && ! in_array($key, ['gclid', 'fbclid', 'msclkid'], true)) {
                $query[] = $part;
            }
        }

        return (string) $uri->withScheme(strtolower($uri->getScheme()))
            ->withHost(strtolower(rtrim($uri->getHost(), '.')))
            ->withPath($uri->getPath() === '' ? '/' : $uri->getPath())
            ->withFragment('')->withQuery(implode('&', $query));
    }

    public static function resolve(string $base, string $reference): string
    {
        return self::normalize((string) UriResolver::resolve(new Uri($base), new Uri($reference)));
    }
}
