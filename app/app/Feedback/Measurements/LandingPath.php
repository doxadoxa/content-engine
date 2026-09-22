<?php

declare(strict_types=1);

namespace App\Feedback\Measurements;

use App\Pages\PageUrl;
use GuzzleHttp\Psr7\Uri;

/** Selecting a path never proves its origin or its ownership by a website. */
final class LandingPath
{
    public static function normalize(string $urlOrPath): string
    {
        $uri = new Uri(PageUrl::normalize($urlOrPath));

        return $uri->getPath().($uri->getQuery() === '' ? '' : '?'.$uri->getQuery());
    }
}
