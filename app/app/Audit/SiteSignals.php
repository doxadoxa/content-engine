<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Contracts\SiteCheck;

/**
 * The four things that are true of a site rather than of any page: its
 * robots.txt, its sitemap, its llms.txt and its TLS.
 *
 * Read once at the top of a sweep by {@see SiteSignalReader} and handed to
 * every {@see SiteCheck}, for the same reason {@see PageFacts} exists — four
 * checks reading the same four files should be four reads, not sixteen.
 *
 * A body is null when the file is not there, and empty when it is there and
 * says nothing. Those are different findings and the distinction has to survive
 * this far.
 */
final readonly class SiteSignals
{
    /**
     * @param  list<string>  $sitemapUrls  the page URLs the sitemap named
     */
    public function __construct(
        public string $siteUrl,
        public ?string $robotsBody = null,
        public ?int $robotsStatus = null,
        public ?string $sitemapUrl = null,
        public ?int $sitemapStatus = null,
        public array $sitemapUrls = [],
        public ?string $llmsBody = null,
        public ?int $llmsStatus = null,
        /** Whether the site answered over HTTPS at all. */
        public bool $isHttps = false,
        /** Whether plain HTTP redirects to it. Null when it was not checked. */
        public ?bool $httpRedirectsToHttps = null,
        /** Set when the TLS handshake itself failed, which is its own finding. */
        public ?string $tlsError = null,
    ) {}

    public function hasRobots(): bool
    {
        return $this->robotsStatus === 200 && $this->robotsBody !== null;
    }

    public function hasLlms(): bool
    {
        return $this->llmsStatus === 200 && $this->llmsBody !== null;
    }

    public function hasSitemap(): bool
    {
        return $this->sitemapStatus === 200 && $this->sitemapUrls !== [];
    }

    /**
     * Does robots.txt shut the whole site out?
     *
     * Only the wildcard agent with a bare `Disallow: /`, which is the case that
     * means "nothing here may be indexed". Narrower rules are a site's business
     * and reporting them as faults would make the check noise.
     *
     * **A group may name several agents.** The format allows consecutive
     * `User-agent` lines to share one set of rules — a group ends at its first
     * rule line, and the next agent line after that begins a new one. So
     *
     *     User-agent: *
     *     User-agent: Googlebot
     *     Disallow: /
     *
     * blocks everything, including the wildcard. Deciding membership from the
     * *last* agent line seen read that as a rule about Googlebot alone and
     * called the site healthy — which is the one verdict this method exists to
     * never get wrong, since it is the difference between an indexable site and
     * a year of writing nothing will ever fetch.
     */
    public function robotsBlocksEverything(): bool
    {
        if ($this->robotsBody === null) {
            return false;
        }

        $groupHasWildcard = false;
        $readingAgents = false;

        foreach (preg_split('/\R/u', $this->robotsBody) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $match) === 1) {
                // An agent line that follows a rule opens a new group; one that
                // follows another agent line joins the group being declared.
                if (! $readingAgents) {
                    $groupHasWildcard = false;
                    $readingAgents = true;
                }

                $groupHasWildcard = $groupHasWildcard || trim($match[1]) === '*';

                continue;
            }

            $readingAgents = false;

            if ($groupHasWildcard && preg_match('/^disallow:\s*\/\s*$/i', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'site_url' => $this->siteUrl,
            'robots_status' => $this->robotsStatus,
            'robots_body' => $this->robotsBody,
            'sitemap_url' => $this->sitemapUrl,
            'sitemap_status' => $this->sitemapStatus,
            'sitemap_urls' => $this->sitemapUrls,
            'llms_status' => $this->llmsStatus,
            'llms_body' => $this->llmsBody,
            'is_https' => $this->isHttps,
            'http_redirects_to_https' => $this->httpRedirectsToHttps,
            'tls_error' => $this->tlsError,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            siteUrl: (string) ($data['site_url'] ?? ''),
            robotsBody: self::stringOrNull($data['robots_body'] ?? null),
            robotsStatus: self::intOrNull($data['robots_status'] ?? null),
            sitemapUrl: self::stringOrNull($data['sitemap_url'] ?? null),
            sitemapStatus: self::intOrNull($data['sitemap_status'] ?? null),
            sitemapUrls: array_values(array_map('strval', (array) ($data['sitemap_urls'] ?? []))),
            llmsBody: self::stringOrNull($data['llms_body'] ?? null),
            llmsStatus: self::intOrNull($data['llms_status'] ?? null),
            isHttps: (bool) ($data['is_https'] ?? false),
            httpRedirectsToHttps: isset($data['http_redirects_to_https'])
                ? (bool) $data['http_redirects_to_https']
                : null,
            tlsError: self::stringOrNull($data['tls_error'] ?? null),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        // Not `!== ''`, unlike PageFacts: an llms.txt that exists and is empty
        // is a different fact from one that is not there, and collapsing the
        // empty string to null would lose exactly that.
        return is_string($value) ? $value : null;
    }
}
