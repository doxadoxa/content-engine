<?php

declare(strict_types=1);

namespace App\Support\Http;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;

final class PublicHttpClient
{
    public function __construct(private readonly PublicHttpTarget $targets) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        int $timeout = 15,
        int $maxRedirects = 0,
        ?string $requiredOrigin = null,
    ): PublicHttpResponse {
        $current = $url;

        for ($redirects = 0; ; $redirects++) {
            $target = $this->targets->validate($current, $requiredOrigin);

            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->retry(0)
                ->withoutRedirecting()
                ->withOptions($target->httpOptions())
                ->send(strtoupper($method), $target->url);

            if (! $response->redirect() || $redirects >= $maxRedirects) {
                return new PublicHttpResponse($response, $target->url);
            }

            $location = trim($response->header('Location'));

            if ($location === '') {
                return new PublicHttpResponse($response, $target->url);
            }

            $current = (string) UriResolver::resolve(new Uri($target->url), new Uri($location));
        }
    }
}
