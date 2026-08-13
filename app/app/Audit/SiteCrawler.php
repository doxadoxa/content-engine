<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Checks\IndexabilityCheck;
use App\Audit\Checks\PageWeightCheck;
use App\Support\Corpus\SiteLibrary;
use App\Support\Http\PublicHttpClient;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fetches one page and reduces it to {@see PageFacts}.
 *
 * Regex rather than a DOM parser, matching {@see
 * \App\Onboarding\HttpSiteReader} and {@see SiteLibrary},
 * which read the same pages for other reasons. The argument is the one those
 * make: real-world HTML is malformed often enough that a strict parser's error
 * path is the common path, and every fact here is a tag or an attribute rather
 * than a tree. The one exception is JSON-LD, which is genuinely structured and
 * is parsed as JSON — which is also the only place a "present but broken"
 * verdict is possible, and worth having.
 *
 * Nothing here throws for a page. A URL that times out produces facts with a
 * null status, {@see IndexabilityCheck} turns that into the
 * finding, and the sweep carries on — one dead page out of a hundred is a
 * result, not a failed audit.
 */
class SiteCrawler
{
    /**
     * How much of a page to read.
     *
     * Everything this extracts lives in the head or is a tag, and a megabyte is
     * far past any reasonable head. The cap is here so one page built by
     * concatenating a database cannot exhaust a worker's memory — and the byte
     * count reported to {@see PageWeightCheck} is the real
     * length from the response, not this truncation.
     */
    private const int MAX_HTML_BYTES = 2_000_000;

    public function __construct(
        private readonly PublicHttpClient $http,
        private readonly PublicHttpTarget $targets,
    ) {}

    /**
     * Read one page.
     *
     * `$origin` is the site being audited, and it is passed to the client as
     * the required origin: a sitemap entry or a redirect that leaves the site
     * is refused rather than followed. Without it this class is a request
     * forgery primitive that fetches whatever a sitemap tells it to.
     */
    public function fetch(string $url, string $origin): PageFacts
    {
        $startedAt = microtime(true);

        try {
            $fetched = $this->http->request(
                'GET',
                $url,
                [
                    'User-Agent' => (string) config('audit.crawl.user_agent', 'ContentEngine/1.0 (+audit)'),
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
                (int) config('audit.crawl.timeout', 15),
                // Redirects are not followed. A sitemap entry that redirects is
                // itself the finding — see IndexabilityCheck — and following it
                // would score the destination under the wrong URL.
                0,
                $origin,
            );
        } catch (ConnectionException|UnsafePublicUrl $e) {
            Log::info('A page could not be crawled', ['url' => $url, 'reason' => $e->getMessage()]);

            return new PageFacts(url: $url, responseMs: $this->elapsed($startedAt));
        }

        $response = $fetched->response;
        $elapsed = $this->elapsed($startedAt);

        if (! $response->successful()) {
            return new PageFacts(
                url: $url,
                statusCode: $response->status(),
                responseMs: $elapsed,
            );
        }

        $body = $response->body();

        return $this->extract(
            url: $url,
            html: Str::limit($body, self::MAX_HTML_BYTES, ''),
            statusCode: $response->status(),
            responseMs: $elapsed,
            htmlBytes: strlen($body),
        );
    }

    /** The facts a page's HTML carries. Public so the checks can be tested on it. */
    public function extract(
        string $url,
        string $html,
        ?int $statusCode = 200,
        ?int $responseMs = null,
        ?int $htmlBytes = null,
    ): PageFacts {
        [$jsonLdTypes, $brokenJsonLd] = $this->jsonLd($html);

        return new PageFacts(
            url: $url,
            statusCode: $statusCode,
            responseMs: $responseMs,
            htmlBytes: $htmlBytes ?? strlen($html),
            title: $this->tag($html, 'title'),
            description: $this->meta($html, 'description'),
            canonical: $this->canonical($html, $url),
            lang: $this->attribute($html, 'html', 'lang'),
            hasViewport: $this->meta($html, 'viewport') !== null,
            isNoIndex: $this->isNoIndex($html),
            headings: $this->headings($html),
            jsonLdTypes: $jsonLdTypes,
            hasBrokenJsonLd: $brokenJsonLd,
            images: $this->images($html),
            internalLinks: $this->internalLinks($html, $url),
        );
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function tag(string $html, string $tag): ?string
    {
        return preg_match("/<{$tag}[^>]*>(.*?)<\\/{$tag}>/is", $html, $m) === 1
            ? $this->clean($m[1])
            : null;
    }

    /**
     * A meta tag's content, by `name` or by `property`.
     *
     * Both attribute orders are tried, because nothing enforces one and a site
     * writing `content` before `name` is not a site without a description.
     */
    private function meta(string $html, string $name): ?string
    {
        $quoted = preg_quote($name, '/');

        $patterns = [
            "/<meta[^>]*(?:name|property)=[\"']{$quoted}[\"'][^>]*content=[\"']([^\"']*)/i",
            "/<meta[^>]*content=[\"']([^\"']*)[\"'][^>]*(?:name|property)=[\"']{$quoted}[\"']/i",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                $value = $this->clean($m[1]);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function attribute(string $html, string $tag, string $attribute): ?string
    {
        return preg_match("/<{$tag}[^>]*\\s{$attribute}=[\"']([^\"']+)/i", $html, $m) === 1
            ? $this->clean($m[1])
            : null;
    }

    /**
     * The canonical link, resolved against the page it was found on.
     *
     * Resolved rather than returned raw so that {@see
     * \App\Audit\Checks\CanonicalUrlCheck} compares two absolute URLs — but the
     * *relative* form is still reported as its own finding, so the resolution
     * here must not hide it. It does not: the check tests the raw shape before
     * this ever matters, and only a relative canonical reaches both branches.
     */
    private function canonical(string $html, string $url): ?string
    {
        if (preg_match('/<link[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)/i', $html, $m) !== 1
            && preg_match('/<link[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\']/i', $html, $m) !== 1) {
            return null;
        }

        return $this->clean($m[1]);
    }

    private function isNoIndex(string $html): bool
    {
        foreach (['robots', 'googlebot'] as $agent) {
            $directive = $this->meta($html, $agent);

            if ($directive !== null && str_contains(mb_strtolower($directive), 'noindex')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Headings in document order, which is what makes a skipped level
     * detectable at all.
     *
     * @return list<array{level: int, text: string}>
     */
    private function headings(string $html): array
    {
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER);

        $headings = [];

        foreach ($matches as $match) {
            $headings[] = [
                'level' => (int) $match[1],
                'text' => (string) $this->clean($match[2]),
            ];
        }

        return $headings;
    }

    /**
     * Every `@type` declared in a JSON-LD block, and whether any block failed
     * to parse.
     *
     * @return array{0: list<string>, 1: bool}
     */
    private function jsonLd(string $html): array
    {
        preg_match_all(
            '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            $html,
            $matches,
        );

        $types = [];
        $broken = false;

        foreach ($matches[1] as $block) {
            $decoded = json_decode(trim($block), true);

            if (! is_array($decoded)) {
                $broken = true;

                continue;
            }

            $types = [...$types, ...$this->typesIn($decoded)];
        }

        return [array_values(array_unique($types)), $broken];
    }

    /**
     * `@type` anywhere in a decoded block: a graph, a list, or a single object.
     *
     * @param  array<mixed>  $decoded
     * @return list<string>
     */
    private function typesIn(array $decoded): array
    {
        $types = [];

        array_walk_recursive($decoded, static function (mixed $value, mixed $key) use (&$types): void {
            if ($key === '@type' && is_string($value) && $value !== '') {
                $types[] = $value;
            }
        });

        // array_walk_recursive only visits leaves, so a `"@type": ["A", "B"]`
        // arrives as two values under the key `0` and `1` and is missed. The
        // top level is where that shape actually occurs.
        foreach (['@type'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_array($value)) {
                $types = [...$types, ...array_values(array_filter(array_map(
                    static fn (mixed $type): string => is_string($type) ? $type : '',
                    $value,
                )))];
            }
        }

        /** @var list<string> $types */
        return $types;
    }

    /**
     * @return list<array{src: string, alt: string|null, has_dimensions: bool, format: string}>
     */
    private function images(string $html): array
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $matches);

        $images = [];

        foreach ($matches[0] as $tag) {
            if (preg_match('/\ssrc=["\']([^"\']+)/i', $tag, $src) !== 1) {
                continue;
            }

            $source = trim($src[1]);

            // A data URI is not a file being served and has no format worth
            // an opinion.
            if ($source === '' || str_starts_with($source, 'data:')) {
                continue;
            }

            $alt = preg_match('/\salt=["\']([^"\']*)/i', $tag, $altMatch) === 1 ? trim($altMatch[1]) : null;

            $images[] = [
                'src' => $source,
                // An empty alt is not a missing one: `alt=""` is the correct
                // marking for a decorative image and reporting it as a fault
                // would punish sites for doing the right thing.
                'alt' => $alt,
                'has_dimensions' => preg_match('/\swidth=/i', $tag) === 1
                    && preg_match('/\sheight=/i', $tag) === 1,
                'format' => $this->formatOf($source),
            ];
        }

        // Capped for the same reason the HTML is: the checks count and sample,
        // and a gallery page should not write a thousand rows into a json
        // column.
        return array_slice($images, 0, 200);
    }

    private function formatOf(string $source): string
    {
        $path = (string) parse_url($source, PHP_URL_PATH);

        return mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Same-origin links, absolute and deduplicated.
     *
     * External links are excluded deliberately. A dead link to somebody else's
     * site is worth knowing about, but checking them means fetching arbitrary
     * addresses from a list a page controls — the exact shape the origin guard
     * exists to prevent — and doing it politely for a hundred pages is a
     * crawler, which is a different product.
     *
     * @return list<string>
     */
    private function internalLinks(string $html, string $pageUrl): array
    {
        preg_match_all('/<a\b[^>]*href=["\']([^"\']+)/i', $html, $matches);

        $origin = $this->originOf($pageUrl);
        $links = [];

        foreach ($matches[1] as $href) {
            $absolute = $this->absolute(trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5)), $pageUrl);

            if ($absolute === null || ! $this->isSameOrigin($absolute, $origin)) {
                continue;
            }

            // The fragment addresses a place on a page, not a page.
            $links[] = Str::before($absolute, '#');
        }

        return array_slice(array_values(array_unique(array_filter($links))), 0, 200);
    }

    /**
     * Is this URL on the site being audited?
     *
     * A prefix test alone is wrong, and wrong in a way that reaches a customer:
     * with an origin of `https://example.com`, `https://example.community/x`
     * and `https://example.com.cdn.net/x` both start with the origin string.
     * Stored as internal, each is then fetched by {@see
     * \App\Pipelines\Steps\Audit\VerifyLinks} with the origin guard on, refused
     * by it, recorded as unreachable and reported to the operator as a *dead
     * internal link* — a live external link presented as their broken page.
     *
     * So the character after the origin has to end the authority: a path, a
     * query, or nothing at all.
     */
    private function isSameOrigin(string $url, string $origin): bool
    {
        if ($origin === '' || ! str_starts_with($url, $origin)) {
            return false;
        }

        $rest = substr($url, strlen($origin));

        return $rest === '' || str_starts_with($rest, '/') || str_starts_with($rest, '?');
    }

    /**
     * A href as an absolute URL, or null if it does not address a page.
     */
    private function absolute(string $href, string $pageUrl): ?string
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        // mailto:, tel:, javascript: — schemes that are not a page.
        if (preg_match('/^(?!https?:)[a-z][a-z0-9+.-]*:/i', $href) === 1) {
            return null;
        }

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        $origin = $this->originOf($pageUrl);

        if (str_starts_with($href, '//')) {
            return Str::before($origin, '//').$href;
        }

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        // Relative to the current directory.
        $path = (string) parse_url($pageUrl, PHP_URL_PATH);
        $directory = rtrim(Str::beforeLast($path === '' ? '/' : $path, '/'), '/');

        return $origin.$directory.'/'.$href;
    }

    private function originOf(string $url): string
    {
        try {
            return $this->targets->origin($url);
        } catch (UnsafePublicUrl) {
            return '';
        }
    }

    private function clean(string $value): ?string
    {
        $cleaned = Str::squish(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $cleaned === '' ? null : $cleaned;
    }
}
