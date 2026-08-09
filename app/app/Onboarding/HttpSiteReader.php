<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Onboarding\Contracts\SiteReader;
use App\Support\Http\PublicHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads a site with an HTTP request and a regex or two.
 *
 * Deliberately not a browser and deliberately not a crawler: onboarding needs
 * enough to describe the business back to somebody and let them correct it, and
 * one page of HTML is enough for that. Anything more is a crawl, which is a
 * different product with different consent questions.
 */
class HttpSiteReader implements SiteReader
{
    public function __construct(private readonly PublicHttpClient $http) {}

    public function read(string $url): SiteSnapshot
    {
        $url = $this->normalise($url);

        try {
            $fetched = $this->http->request(
                'GET',
                $url,
                ['User-Agent' => 'ContentEngine/1.0 (+onboarding)'],
                (int) config('onboarding.timeout', 15),
                3,
            );
            $response = $fetched->response;
            $url = $fetched->url;
        } catch (ConnectionException $e) {
            throw new RuntimeException("We could not reach {$url}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new RuntimeException("{$url} answered {$response->status()}.");
        }

        $html = $response->body();

        return new SiteSnapshot(
            url: $url,
            title: $this->tag($html, 'title'),
            description: $this->meta($html, 'description'),
            language: $this->attribute($html, 'html', 'lang'),
            sitemapUrl: $this->sitemap($url),
            headings: $this->headings($html),
            links: $this->links($html),
            // A slice, not the page: this goes into a prompt, and the footer of
            // a marketing site is mostly navigation.
            text: Str::limit($this->text($html), (int) config('onboarding.text_limit', 6000), ''),
        );
    }

    /**
     * Refuse anything that is not a plain public http(s) URL.
     *
     * The wizard hands this a string somebody typed, and it is fetched by our
     * server — which is the shape of a request-forgery hole. Localhost and
     * private ranges are the addresses an attacker wants us to reach.
     */
    private function normalise(string $url): string
    {
        $url = trim($url);

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new RuntimeException('That does not look like a website address.');
        }

        return $url;
    }

    private function tag(string $html, string $tag): string
    {
        return preg_match("/<{$tag}[^>]*>(.*?)<\\/{$tag}>/is", $html, $m) === 1
            ? $this->clean($m[1])
            : '';
    }

    private function meta(string $html, string $name): string
    {
        foreach (["name=[\"']{$name}[\"']", "property=[\"']og:{$name}[\"']"] as $selector) {
            if (preg_match("/<meta[^>]*{$selector}[^>]*content=[\"']([^\"']*)/i", $html, $m) === 1) {
                return $this->clean($m[1]);
            }
        }

        return '';
    }

    private function attribute(string $html, string $tag, string $attribute): ?string
    {
        return preg_match("/<{$tag}[^>]*{$attribute}=[\"']([^\"']+)/i", $html, $m) === 1
            ? $this->clean($m[1])
            : null;
    }

    /** @return list<string> */
    private function headings(string $html): array
    {
        preg_match_all('/<h[123][^>]*>(.*?)<\/h[123]>/is', $html, $matches);

        /** @var list<string> $headings */
        $headings = array_values(array_unique(array_filter(
            array_map(fn (string $heading): string => $this->clean($heading), $matches[1]),
            static fn (string $heading): bool => $heading !== '',
        )));

        return $headings;
    }

    /** @return list<string> */
    private function links(string $html): array
    {
        preg_match_all('/<a[^>]*href=["\']([^"\'#?]+)/i', $html, $matches);

        $paths = array_filter(
            $matches[1],
            static fn (string $href): bool => str_starts_with($href, '/') && strlen($href) > 1,
        );

        /** @var list<string> $links */
        $links = array_slice(array_values(array_unique($paths)), 0, 40);

        return $links;
    }

    private function sitemap(string $url): ?string
    {
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host'])
            ? "{$parts['scheme']}://{$parts['host']}/sitemap.xml"
            : null;
    }

    private function text(string $html): string
    {
        $stripped = preg_replace('/<(script|style|nav|footer|svg)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;

        return $this->clean(strip_tags($stripped));
    }

    private function clean(string $value): string
    {
        return Str::squish(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
    }
}
