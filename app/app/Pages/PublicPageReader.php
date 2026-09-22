<?php

declare(strict_types=1);

namespace App\Pages;

use App\Models\Project;
use App\Support\Http\PublicHttpClient;
use App\Support\Http\PublicHttpTarget;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublicPageReader
{
    public function __construct(private readonly PublicHttpClient $http, private readonly PublicHttpTarget $targets, private readonly FactualPageSurfaces $facts) {}

    /** @return array{url: string, canonical_url: string, locale: string, fields: array<string, string>, content_hash: string, description_count: int, fact_surfaces: array<string, mixed>} */
    public function read(Project $project, string $url, string $locale): array
    {
        $origin = $project->website_url ?: $project->sitemap_url;
        if (! $origin) {
            throw ValidationException::withMessages(['url' => 'Set this project’s website address before importing pages.']);
        }
        $url = PageUrl::normalize($url);
        $result = $this->http->request('GET', $url, ['Accept' => 'text/html', 'User-Agent' => 'Avyo/1.0 (+page-review)'], 15, 3, $origin, 2_000_000);
        if (! $result->response->successful()) {
            throw ValidationException::withMessages(['url' => 'The page did not return a successful response. No snapshot was saved.']);
        }
        $html = $result->response->body();
        if (strlen($html) > 2_000_000 || $html === '') {
            throw ValidationException::withMessages(['url' => 'The page is empty or exceeds the 2 MB snapshot limit.']);
        }
        $type = strtolower($result->response->header('Content-Type'));
        if ($type !== '' && ! str_contains($type, 'text/html') && ! str_contains($type, 'application/xhtml+xml')) {
            throw ValidationException::withMessages(['url' => 'Import an HTML page, not a document or API response.']);
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw ValidationException::withMessages(['url' => 'The page could not be read as HTML.']);
        }
        $xpath = new DOMXPath($doc);
        $actualLocale = trim((string) $xpath->evaluate('string(/html/@lang)'));
        if ($actualLocale !== '' && strtolower(explode('-', str_replace('_', '-', $actualLocale))[0]) !== strtolower(explode('-', $locale)[0])) {
            throw ValidationException::withMessages(['locale' => 'The live page declares a different language. Select that language before importing it.']);
        }
        $canonical = trim((string) $xpath->evaluate('string(//link[contains(concat(" ", normalize-space(@rel), " "), " canonical ")]/@href)'));
        $canonical = $canonical === '' ? PageUrl::normalize($result->url) : PageUrl::resolve($result->url, $canonical);
        $this->targets->validate($canonical, $origin);
        $prefix = strtolower(explode('/', ltrim((string) parse_url($canonical, PHP_URL_PATH), '/'))[0]);
        $languages = array_map(static fn (string $value): string => strtolower(explode('-', $value)[0]), [$project->default_locale, ...$project->locales]);
        if (in_array($prefix, $languages, true) && $prefix !== strtolower(explode('-', $locale)[0])) {
            throw ValidationException::withMessages(['locale' => 'The canonical URL points to another project language. Correct the page canonical before tracking it.']);
        }
        $title = Str::squish((string) $xpath->evaluate('string(//title)'));
        $description = trim((string) $xpath->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content)'));
        $factSurfaces = $this->facts->capture($doc);

        // The source is retained for revision comparison but is never rendered as trusted HTML.
        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//header|//footer') ?: [] as $node) {
            if ($node instanceof DOMNode) {
                $node->parentNode?->removeChild($node);
            }
        }
        $body = null;
        foreach (['//main', '//article', '//body'] as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes !== false && $nodes->length > 0) {
                $body = $nodes->item(0);
                break;
            }
        }
        $bodyHtml = $body instanceof DOMElement ? (string) $doc->saveHTML($body) : '';
        $bodyText = Str::squish(html_entity_decode(strip_tags((string) preg_replace('/<\/(p|div|li|h[1-6]|td|tr)>/i', ' ', $bodyHtml)), ENT_QUOTES | ENT_HTML5));
        $fields = ['title' => $title, 'description' => $description, 'body_html' => $bodyHtml, 'body_text' => $bodyText];

        return ['url' => PageUrl::normalize($result->url), 'canonical_url' => $canonical, 'locale' => $locale, 'fields' => $fields, 'content_hash' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'description_count' => (int) $xpath->evaluate('count(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"])'), 'fact_surfaces' => $factSurfaces];
    }
}
