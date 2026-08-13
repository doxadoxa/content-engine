<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Audit\Checks\LlmsTxtCheck;
use App\Audit\Checks\RobotsTxtCheck;
use App\Audit\Checks\SitemapCheck;
use App\Audit\Checks\SslCertificateCheck;
use App\Audit\SiteSignals;
use App\Enums\AuditSeverity;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The four checks that are about the site rather than about any page.
 *
 * The one that matters most is the wildcard `Disallow: /`: it is invisible from
 * a browser, it takes an entire site out of every index, and until this section
 * existed nothing in the engine could see it. Every article written for such a
 * site is money spent on a page nothing will ever fetch.
 */
final class SiteChecksTest extends TestCase
{
    #[Test]
    public function a_robots_file_shutting_every_crawler_out_is_the_worst_finding_available(): void
    {
        $findings = (new RobotsTxtCheck)->run($this->signals([
            'robots_body' => "User-agent: *\nDisallow: /\n",
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame(AuditSeverity::High, $findings[0]->severity);
    }

    #[Test]
    public function a_narrower_disallow_is_the_sites_own_business(): void
    {
        // Real sites block /admin and /cart. Reporting those would make the
        // check noise, and noise is how a real `Disallow: /` gets scrolled past.
        $this->assertSame([], (new RobotsTxtCheck)->run($this->signals([
            'robots_body' => "User-agent: *\nDisallow: /admin\nDisallow: /cart\nSitemap: https://example.test/sitemap.xml\n",
        ])));
    }

    #[Test]
    public function a_blanket_disallow_aimed_at_one_bot_is_not_a_site_wide_block(): void
    {
        $this->assertSame([], (new RobotsTxtCheck)->run($this->signals([
            'robots_body' => "User-agent: BadBot\nDisallow: /\n\nUser-agent: *\nDisallow:\nSitemap: https://example.test/sitemap.xml\n",
        ])));
    }

    #[Test]
    public function a_group_naming_several_agents_still_counts_as_the_wildcard(): void
    {
        // Consecutive User-agent lines share one set of rules, so this blocks
        // everything — including the wildcard. Deciding membership from the last
        // agent line read it as a rule about Googlebot alone and called a
        // de-indexed site healthy, which is the single most expensive verdict
        // this check can get wrong.
        $findings = (new RobotsTxtCheck)->run($this->signals([
            'robots_body' => "User-agent: *\nUser-agent: Googlebot\nDisallow: /\n",
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame(AuditSeverity::High, $findings[0]->severity);
    }

    #[Test]
    public function a_wildcard_group_that_has_already_ended_does_not_leak_into_the_next(): void
    {
        // The wildcard group ends at its own rule line; the Googlebot group
        // that follows is a separate one, and its `Disallow: /` is that site's
        // business rather than a site-wide block.
        $this->assertSame([], (new RobotsTxtCheck)->run($this->signals([
            'robots_body' => "User-agent: *\nDisallow: /admin\nSitemap: https://example.test/sitemap.xml\n\n"
                ."User-agent: Googlebot\nDisallow: /\n",
        ])));
    }

    #[Test]
    public function a_commented_out_disallow_is_not_a_disallow(): void
    {
        $this->assertSame([], (new RobotsTxtCheck)->run($this->signals([
            'robots_body' => "User-agent: *\n# Disallow: /\nSitemap: https://example.test/sitemap.xml\n",
        ])));
    }

    #[Test]
    public function a_missing_sitemap_is_high_because_this_engine_reads_it_too(): void
    {
        $findings = (new SitemapCheck)->run($this->signals([
            'sitemap_status' => 404,
            'sitemap_urls' => [],
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame(AuditSeverity::High, $findings[0]->severity);
    }

    #[Test]
    public function a_sitemap_that_lists_nothing_is_as_bad_as_no_sitemap(): void
    {
        $findings = (new SitemapCheck)->run($this->signals(['sitemap_urls' => []]));

        $this->assertCount(1, $findings);
        $this->assertSame(AuditSeverity::High, $findings[0]->severity);
    }

    #[Test]
    public function an_llms_file_that_exists_and_says_nothing_is_still_a_finding(): void
    {
        $findings = (new LlmsTxtCheck)->run($this->signals(['llms_body' => "\n"]));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('nearly empty', $findings[0]->summary);
    }

    #[Test]
    public function a_real_llms_file_passes(): void
    {
        $this->assertSame([], (new LlmsTxtCheck)->run($this->signals([
            'llms_body' => "# Cleaning Point\n\nA home cleaning service in Lisbon, booked online and priced per room.\n",
        ])));
    }

    #[Test]
    public function a_site_that_serves_both_schemes_without_redirecting_splits_its_own_signals(): void
    {
        $findings = (new SslCertificateCheck)->run($this->signals([
            'http_redirects_to_https' => false,
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame(AuditSeverity::Medium, $findings[0]->severity);
    }

    #[Test]
    public function a_redirect_that_could_not_be_checked_is_not_reported_as_a_fault(): void
    {
        // Null is "not checked". Inventing a fault out of our own incompleteness
        // is the one thing an audit must never do.
        $this->assertSame([], (new SslCertificateCheck)->run($this->signals([
            'http_redirects_to_https' => null,
        ])));
    }

    /**
     * A healthy site, with whatever is under test overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function signals(array $overrides = []): SiteSignals
    {
        return SiteSignals::fromArray([
            'site_url' => 'https://example.test',
            'robots_status' => 200,
            'robots_body' => "User-agent: *\nAllow: /\nSitemap: https://example.test/sitemap.xml\n",
            'sitemap_url' => 'https://example.test/sitemap.xml',
            'sitemap_status' => 200,
            'sitemap_urls' => ['https://example.test/', 'https://example.test/services'],
            'llms_status' => 200,
            'llms_body' => "# Example\n\nA business that does a thing, described in a sentence.\n",
            'is_https' => true,
            'http_redirects_to_https' => true,
            'tls_error' => null,
            ...$overrides,
        ]);
    }
}
