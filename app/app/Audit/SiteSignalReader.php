<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Project;
use App\Support\Corpus\SiteLibrary;
use App\Support\Http\PublicHttpClient;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Reads the four files that describe a site rather than a page.
 *
 * Every fetch here is best-effort by design. A missing robots.txt is a finding,
 * not an outage, and a reader that threw on one would turn the most common
 * result the audit exists to report into a failed run. The only thing it can
 * refuse outright is a project with no website URL, which is a state the sweep
 * cannot start from at all.
 *
 * The sitemap is parsed here rather than in the crawler because two different
 * consumers want it: {@see SitemapCheck} wants to know it exists and lists
 * something, and the crawler wants the list. Reading it twice would be two
 * requests to somebody else's server for one question.
 */
class SiteSignalReader
{
    /**
     * The same ceiling {@see SiteLibrary} uses. Enough for a
     * small business site; a larger one does not need all of it audited to know
     * what is wrong with it.
     */
    private const int MAX_SITEMAP_URLS = 500;

    /**
     * How many sitemaps an index may send us to.
     *
     * A large shop's index names dozens, one per thousand products. The page
     * ceiling above binds long before this does on any site the audit is for,
     * so this exists to bound the *requests* rather than the result.
     */
    private const int MAX_CHILD_SITEMAPS = 20;

    public function __construct(
        private readonly PublicHttpClient $http,
        private readonly PublicHttpTarget $targets,
    ) {}

    public function read(Project $project): SiteSignals
    {
        $siteUrl = trim((string) $project->website_url);

        $origin = $this->targets->origin($siteUrl);

        $robots = $this->fetch($origin.'/robots.txt', $origin);
        $llms = $this->fetch($origin.'/llms.txt', $origin);

        // The project's own sitemap setting first: a site that keeps its
        // sitemap somewhere unusual said so during onboarding, and guessing
        // /sitemap.xml over the answer we were given would report a missing
        // sitemap for a site that has one.
        $sitemapUrl = trim((string) $project->sitemap_url) ?: $origin.'/sitemap.xml';
        $sitemap = $this->fetch($sitemapUrl, $origin);

        [$isHttps, $tlsError] = $this->readHttps($origin);

        return new SiteSignals(
            siteUrl: $siteUrl,
            robotsBody: $robots['body'],
            robotsStatus: $robots['status'],
            sitemapUrl: $sitemapUrl,
            sitemapStatus: $sitemap['status'],
            sitemapUrls: $sitemap['body'] === null ? [] : $this->urlsFrom($sitemap['body'], $sitemapUrl, $origin),
            llmsBody: $llms['body'],
            llmsStatus: $llms['status'],
            isHttps: $isHttps,
            httpRedirectsToHttps: $isHttps ? $this->httpRedirects($origin) : null,
            tlsError: $tlsError,
        );
    }

    /**
     * Whether the origin answers over HTTPS at all.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function readHttps(string $origin): array
    {
        if (! str_starts_with($origin, 'https://')) {
            return [false, null];
        }

        $result = $this->fetch($origin.'/', $origin);

        if ($result['status'] !== null) {
            return [true, null];
        }

        // Reached only when the origin would not answer at all. That is a
        // certificate the client refused, a server that is down, a name that
        // does not resolve from this worker, or the outbound guard declining
        // the address — and nothing here can tell those apart. The value is
        // carried as "unreachable over HTTPS" rather than as a certificate
        // verdict; see SslCertificateCheck, which used to word this as "the
        // certificate could not be verified" and so told customers whose site
        // was briefly down that their TLS was broken.
        return [false, $result['error']];
    }

    /**
     * Does plain HTTP send visitors to HTTPS?
     *
     * Null when the question could not be answered — an http:// origin that
     * refuses connections tells us nothing, and {@see SslCertificateCheck}
     * treats null as "not checked" rather than as a fault.
     */
    private function httpRedirects(string $origin): ?bool
    {
        $plain = 'http://'.parse_url($origin, PHP_URL_HOST);

        try {
            // No redirects followed on purpose: the answer *is* the redirect.
            // Following it would land on the https page and report success for
            // a site that served a 200 over plain http.
            $response = $this->http->request('GET', $plain.'/', $this->headers(), $this->timeout(), 0)->response;
        } catch (ConnectionException|UnsafePublicUrl) {
            return null;
        }

        if (! $response->redirect()) {
            return false;
        }

        return str_starts_with(mb_strtolower(trim($response->header('Location'))), 'https://');
    }

    /**
     * One file, or nothing.
     *
     * @return array{status: int|null, body: string|null, error: string|null}
     */
    private function fetch(string $url, string $origin): array
    {
        try {
            $response = $this->http->request(
                'GET',
                $url,
                $this->headers(),
                $this->timeout(),
                // Redirects followed: a site that serves /robots.txt from a
                // canonical host still has a robots.txt. Bounded by the origin
                // check inside the client, so a redirect off the site is
                // refused rather than followed.
                3,
                $origin,
            )->response;
        } catch (ConnectionException|UnsafePublicUrl $e) {
            Log::info('A site signal could not be read', ['url' => $url, 'reason' => $e->getMessage()]);

            return ['status' => null, 'body' => null, 'error' => $e->getMessage()];
        }

        return [
            'status' => $response->status(),
            'body' => $response->successful() ? $response->body() : null,
            'error' => null,
        ];
    }

    /**
     * Page URLs from a sitemap, following one level of index if that is what it
     * turns out to be.
     *
     * **Following the index is not a nicety.** `/sitemap.xml` on WordPress with
     * Yoast, on Shopify, and on most CMSs is a `<sitemapindex>` naming the real
     * sitemaps — it lists no pages at all. Reading only the top level there
     * produced two wrong answers at once: {@see SitemapCheck} reported "the
     * sitemap is there but names no pages" as a *high* finding about a
     * perfectly good sitemap, and the crawl fell back to the home page alone,
     * so a hundred-page site was scored on one page.
     *
     * One level and no further. Nesting beyond that is legal and vanishingly
     * rare, and each level is another round of requests to somebody else's
     * server; the page budget binds long before it matters.
     *
     * Same origin guard as {@see SiteLibrary::fromSitemap()}, applied to the
     * child sitemaps as well as to the pages: a sitemap is a list of addresses
     * somebody else controls, and every one of them is about to be fetched by
     * our server.
     *
     * @return list<string>
     */
    private function urlsFrom(string $body, string $sitemapUrl, string $origin, bool $followIndex = true): array
    {
        preg_match_all('/<loc>\s*([^<\s]+)\s*<\/loc>/i', $body, $matches);

        $urls = [];
        $children = [];

        foreach ($matches[1] as $raw) {
            $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

            if (! $this->isOnSite($url, $sitemapUrl)) {
                continue;
            }

            if ($this->looksLikeSitemap($url)) {
                $children[] = $url;

                continue;
            }

            if (count($urls) < self::MAX_SITEMAP_URLS) {
                $urls[] = $url;
            }
        }

        if ($followIndex && $children !== []) {
            $urls = [...$urls, ...$this->fromChildSitemaps($children, $origin)];
        }

        return array_slice(array_values(array_unique($urls)), 0, self::MAX_SITEMAP_URLS);
    }

    /**
     * The pages named by the sitemaps an index points at.
     *
     * Bounded by {@see MAX_CHILD_SITEMAPS} because a large shop's index can name
     * dozens, and by the URL ceiling because what the crawl can use is bounded
     * anyway. A child that will not load is skipped rather than fatal: a partial
     * list of pages is a better audit than none.
     *
     * @param  list<string>  $children
     * @return list<string>
     */
    private function fromChildSitemaps(array $children, string $origin): array
    {
        $urls = [];

        foreach (array_slice($children, 0, self::MAX_CHILD_SITEMAPS) as $child) {
            if (count($urls) >= self::MAX_SITEMAP_URLS) {
                break;
            }

            $fetched = $this->fetch($child, $origin);

            if ($fetched['body'] === null) {
                Log::info('A child sitemap could not be read', [
                    'sitemap' => $child,
                    'status' => $fetched['status'],
                ]);

                continue;
            }

            // No further recursion: `$followIndex` is false, so an index inside
            // an index contributes its pages and not its children.
            $urls = [...$urls, ...$this->urlsFrom($fetched['body'], $child, $origin, followIndex: false)];
        }

        return $urls;
    }

    /**
     * Does this `<loc>` name a sitemap rather than a page?
     *
     * The extension, ignoring any query string — `…/wp-sitemap-posts-post-1.xml`
     * and `…/sitemap.xml?page=2` are both sitemaps, and the second one used to
     * be crawled as HTML and reported as a page with no title, no h1 and no
     * structured data.
     *
     * `.xml.gz` counts too, and is then dropped by {@see fromChildSitemaps()}
     * because the body is compressed. Recognising it as a sitemap is still
     * right: what matters most is that it never reaches the crawler as a page.
     */
    private function looksLikeSitemap(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_ends_with($path, '.xml') || str_ends_with($path, '.xml.gz');
    }

    /** Whether a `<loc>` is somewhere we are willing to fetch. */
    private function isOnSite(string $url, string $sitemapUrl): bool
    {
        try {
            $this->targets->validate($url, $sitemapUrl);

            return true;
        } catch (UnsafePublicUrl $e) {
            Log::notice('A sitemap entry was outside the site being audited', [
                'sitemap' => $sitemapUrl,
                'url' => $url,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['User-Agent' => (string) config('audit.crawl.user_agent', 'ContentEngine/1.0 (+audit)')];
    }

    private function timeout(): int
    {
        return (int) config('audit.crawl.timeout', 15);
    }
}
