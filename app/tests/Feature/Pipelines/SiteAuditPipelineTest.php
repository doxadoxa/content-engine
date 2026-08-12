<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Audit\PageSpeed\Contracts\PageSpeedGateway;
use App\Audit\PageSpeed\FakePageSpeed;
use App\Audit\PageSpeed\PageSpeedReading;
use App\Console\Commands\EngineTickCommand;
use App\Enums\AuditSeverity;
use App\Enums\PipelineRunStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\SiteAuditPipeline;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sweep, end to end against a site made of `Http::fake()`.
 *
 * Four things this file is really about, in order of how expensive they would
 * be to get wrong:
 *
 *   - a sitemap entry pointing off the site is refused rather than fetched,
 *     because a sitemap is a list of addresses somebody else controls and our
 *     server is the one about to fetch them;
 *   - a score is null when nothing was measured and a number when something
 *     was, in both directions: an installation with no PageSpeed key still
 *     scores what the crawler timed itself, and a site whose every page is down
 *     is not reported as flawlessly fast;
 *   - every step runs on the audit queue, which is the whole reason the feature
 *     has a pool of its own;
 *   - a page that will not load is a finding rather than a failed run.
 */
final class SiteAuditPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakePageSpeed $pageSpeed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'name' => 'Cleaning Point',
            'website_url' => 'https://example.test',
            'sitemap_url' => 'https://example.test/sitemap.xml',
        ]);

        app(CurrentProject::class)->set($this->project);

        /** @var FakePageSpeed $pageSpeed */
        $pageSpeed = app(PageSpeedGateway::class);
        $this->pageSpeed = $pageSpeed;

        config()->set('queue.default', 'sync');
    }

    // -------------------------------------------------------- the happy path

    #[Test]
    public function it_reads_the_site_and_scores_it(): void
    {
        $this->fakeSite();

        $run = $this->sweep();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $audit = SiteAudit::newest();

        $this->assertNotNull($audit);
        $this->assertNotNull($audit->finished_at, 'A scored sweep is a finished one.');
        // The three the sitemap names, plus the home page, which the sweep adds
        // whether or not the sitemap mentions it.
        $this->assertSame(4, $audit->pages_crawled);
        $this->assertNotNull($audit->health_score);
        $this->assertNotNull($audit->seo_score);
        $this->assertNotNull($audit->geo_score);
    }

    #[Test]
    public function findings_are_attributed_to_the_page_they_are_about(): void
    {
        $this->fakeSite();

        $this->sweep();

        $broken = SiteAuditPage::query()->where('url', 'https://example.test/broken')->firstOrFail();

        $summaries = SiteAuditIssue::query()
            ->where('site_audit_page_id', $broken->getKey())
            ->pluck('check_key')
            ->all();

        $this->assertContains('meta_description', $summaries);
        $this->assertContains('json_ld_schema', $summaries);

        $clean = SiteAuditPage::query()->where('url', 'https://example.test/services')->firstOrFail();

        $this->assertSame(0, $clean->issues_count, 'A healthy page should carry nothing.');
        $this->assertSame(100, $clean->score);
    }

    #[Test]
    public function the_site_level_checks_are_recorded_even_when_they_pass(): void
    {
        $this->fakeSite();

        $this->sweep();

        $audit = SiteAudit::newest();

        $this->assertNotNull($audit);

        $checks = $audit->site_checks;

        // The screen shows all four with an "Ok" beside the healthy ones, so an
        // absence here would render as "not checked".
        $this->assertArrayHasKey('robots_txt', $checks);
        $this->assertArrayHasKey('sitemap_xml', $checks);
        $this->assertArrayHasKey('llms_txt', $checks);
        $this->assertArrayHasKey('ssl_certificate', $checks);
        $this->assertTrue($checks['robots_txt']['ok']);
    }

    // ------------------------------------------------------------- the guard

    #[Test]
    public function a_sitemap_entry_pointing_off_the_site_is_never_fetched(): void
    {
        $this->fakeSite(sitemapExtra: ['https://elsewhere.test/internal-admin']);

        $this->sweep();

        Http::assertNotSent(
            static fn ($request): bool => str_contains($request->url(), 'elsewhere.test'),
        );

        $this->assertSame(
            0,
            SiteAuditPage::query()->where('url', 'like', '%elsewhere.test%')->count(),
        );
    }

    #[Test]
    public function a_page_that_will_not_load_is_a_finding_rather_than_a_failed_sweep(): void
    {
        $this->fakeSite();

        $run = $this->sweep();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $gone = SiteAuditPage::query()->where('url', 'https://example.test/gone')->firstOrFail();

        $issues = SiteAuditIssue::query()->where('site_audit_page_id', $gone->getKey())->get();

        $this->assertCount(1, $issues, 'A dead page gets one clear finding, not eight restatements of it.');
        $this->assertSame('indexability', $issues[0]->check_key);
        $this->assertSame(AuditSeverity::High, $issues[0]->severity);
    }

    // -------------------------------------------------------------- the pool

    #[Test]
    public function every_step_runs_on_the_audit_queue(): void
    {
        $expected = (string) config('pipeline.queues.audit');

        foreach ((new SiteAuditPipeline)->steps() as $class) {
            $this->assertSame(
                $expected,
                app($class)->queue(),
                "{$class} must not land on a shared pool: a crawl in front of the cheap queue is an "
                    .'article waiting on a customer TLS handshake.',
            );
        }
    }

    #[Test]
    public function the_audit_is_not_part_of_the_article_contour(): void
    {
        $contour = (new \ReflectionClass(EngineTickCommand::class))
            ->getConstant('CONTOUR');

        $this->assertIsArray($contour);
        $this->assertNotContains(
            SiteAuditPipeline::key(),
            $contour,
            'A ten-minute crawl must not stall an hour of drafting, and vice versa.',
        );
    }

    // ------------------------------------------------------------- page speed

    #[Test]
    public function an_installation_with_no_pagespeed_key_still_scores_what_it_measured_itself(): void
    {
        $this->pageSpeed->unconfigured();
        $this->fakeSite();

        $run = $this->sweep();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status, 'No key is not a failure.');

        $audit = SiteAudit::newest();

        // Not null, and not zero either. The crawler timed every page it
        // fetched, so performance was genuinely measured — just without
        // Lighthouse. Scoring it zero would report every keyless installation's
        // sites as slow; scoring it null would throw away numbers we have.
        $this->assertNotNull($audit?->speed_score);
        $this->assertGreaterThan(0, $audit->speed_score);
        $this->assertNotNull($audit->health_score);

        $this->assertSame(
            0,
            SiteAuditPage::query()->whereNotNull('speed')->count(),
            'Nothing should have been asked of a vendor that is not configured.',
        );
        $this->assertSame([], $this->pageSpeed->measuredUrls());
    }

    #[Test]
    public function a_site_whose_every_page_is_down_is_not_reported_as_fast(): void
    {
        Http::fake([
            'https://example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'https://example.test/sitemap.xml' => Http::response('', 404),
            'https://example.test/llms.txt' => Http::response('', 404),
            '*' => Http::response('', 500),
        ]);

        $this->sweep();

        $audit = SiteAudit::newest();

        // Nothing was readable, so no performance check ever ran. Its silence
        // is absence of evidence, and scoring it a hundred would report a site
        // that is entirely down as flawlessly fast.
        $this->assertNull($audit?->speed_score);

        // The site-level checks did run, so the groups they belong to are
        // measured: robots.txt was read, the sitemap 404ed and so did llms.txt.
        $this->assertNotNull($audit?->seo_score);
        $this->assertNotNull($audit->geo_score);
        $this->assertLessThan(100, $audit->geo_score, 'A missing llms.txt is a real finding.');
    }

    #[Test]
    public function lighthouse_measures_the_home_page_first(): void
    {
        config()->set('audit.page_speed.max_pages', 1);
        $this->fakeSite();

        $this->sweep();

        $this->assertSame(['https://example.test/'], $this->pageSpeed->measuredUrls());
    }

    #[Test]
    public function a_slow_page_drags_the_speed_score_down(): void
    {
        $this->pageSpeed->script(
            'https://example.test/',
            new PageSpeedReading(score: 20, largestContentfulPaintMs: 8_400),
        );
        config()->set('audit.page_speed.max_pages', 1);
        $this->fakeSite();

        $this->sweep();

        $this->assertLessThan(60, SiteAudit::newest()?->speed_score);
    }

    // ------------------------------------------------------------ dead links

    #[Test]
    public function a_dead_internal_link_is_reported_against_the_page_that_carries_it(): void
    {
        $this->fakeSite();

        $this->sweep();

        $issues = SiteAuditIssue::query()->where('check_key', 'broken_links')->get();

        $this->assertNotEmpty($issues, 'The thin page links to /gone, which 404s.');
        $this->assertStringContainsString('no longer resolve', $issues[0]->summary);

        $carrier = SiteAuditPage::query()->whereKey($issues[0]->site_audit_page_id)->firstOrFail();

        $this->assertSame('https://example.test/broken', $carrier->url);
    }

    #[Test]
    public function an_exhausted_link_budget_still_reports_the_verdicts_that_cost_nothing(): void
    {
        $this->fakeSite();

        // One request's worth of budget, spent on something else. The dead link
        // on /broken points at /gone, which this sweep already crawled — so its
        // verdict is free, and a budget check placed before the lookup used to
        // throw it away along with every page after it.
        $this->sweep(['max_links' => 1]);

        $this->assertSame(
            1,
            SiteAuditIssue::query()->where('check_key', 'broken_links')->count(),
        );
    }

    #[Test]
    public function a_link_budget_of_zero_skips_the_branch_without_failing_the_sweep(): void
    {
        $this->fakeSite();

        $run = $this->sweep(['max_links' => 0]);

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(0, SiteAuditIssue::query()->where('check_key', 'broken_links')->count());
    }

    // ------------------------------------------------------------- no sitemap

    // -------------------------------------------------------- sitemap indexes

    #[Test]
    public function a_sitemap_index_is_followed_to_the_pages_it_names(): void
    {
        // What WordPress with Yoast, Shopify and most CMSs actually serve at
        // /sitemap.xml. Read only at the top level it lists no pages at all —
        // so the audit used to raise a *high* "the sitemap names no pages"
        // about a perfectly good sitemap and then score the whole site from
        // the home page alone.
        $this->fakeIndexedSite();

        $run = $this->sweep();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $audit = SiteAudit::newest();

        $this->assertNotNull($audit);
        $this->assertTrue($audit->site_checks['sitemap_xml']['ok'], 'An index is a working sitemap.');

        $crawled = SiteAuditPage::query()->pluck('url')->all();

        $this->assertContains('https://example.test/services', $crawled);
        $this->assertContains('https://example.test/journal/one', $crawled);
        $this->assertSame(4, $audit->pages_crawled, 'Two child sitemaps of two pages, plus the home page.');
    }

    #[Test]
    public function a_child_sitemap_is_never_crawled_as_though_it_were_a_page(): void
    {
        $this->fakeIndexedSite();

        $this->sweep();

        // A `<loc>` naming a sitemap is a sitemap however it is spelled. One
        // crawled as HTML produces "no title, no h1, no canonical, no
        // structured data" — four findings about an XML file.
        $this->assertSame(
            0,
            SiteAuditPage::query()->where('url', 'like', '%sitemap%')->count(),
        );
    }

    #[Test]
    public function a_child_sitemap_that_will_not_load_costs_only_its_own_pages(): void
    {
        $this->fakeIndexedSite(brokenChild: true);

        $run = $this->sweep();

        // A partial list of pages is a better audit than none.
        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertContains(
            'https://example.test/services',
            SiteAuditPage::query()->pluck('url')->all(),
        );
    }

    #[Test]
    public function a_site_with_no_sitemap_still_produces_a_readable_audit(): void
    {
        Http::fake([
            'https://example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'https://example.test/sitemap.xml' => Http::response('', 404),
            'https://example.test/llms.txt' => Http::response('', 404),
            'https://example.test/' => Http::response('<html><head><title>Home</title></head><body></body></html>'),
            '*' => Http::response('', 404),
        ]);

        $run = $this->sweep();

        // Not a failed run: the missing sitemap is the finding, and an operator
        // should see it on the screen rather than a red pipeline nobody reads.
        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $audit = SiteAudit::newest();

        $this->assertNotNull($audit);
        $this->assertFalse($audit->site_checks['sitemap_xml']['ok']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function sweep(array $input = []): PipelineRun
    {
        return app(PipelineRunner::class)->start(SiteAuditPipeline::key(), $this->project, $input);
    }

    /**
     * A small site: one healthy page, one thin page, one that 404s.
     *
     * @param  list<string>  $sitemapExtra
     */
    private function fakeSite(array $sitemapExtra = []): void
    {
        $urls = [
            'https://example.test/services',
            'https://example.test/broken',
            'https://example.test/gone',
            ...$sitemapExtra,
        ];

        $locs = implode('', array_map(
            static fn (string $url): string => "<url><loc>{$url}</loc></url>",
            $urls,
        ));

        Http::fake([
            'https://example.test/robots.txt' => Http::response(
                "User-agent: *\nAllow: /\nSitemap: https://example.test/sitemap.xml\n",
            ),
            'https://example.test/llms.txt' => Http::response(
                "# Cleaning Point\n\nA home cleaning service in Lisbon, booked online.\n",
            ),
            'https://example.test/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'.$locs.'</urlset>',
            ),
            'https://example.test/services' => Http::response($this->healthyPage()),
            'https://example.test/broken' => Http::response($this->thinPage()),
            'https://example.test/gone' => Http::response('', 404),
            // The home page, added by the sweep whether or not the sitemap
            // names it.
            'https://example.test/' => Http::response($this->healthyPage()),
            '*' => Http::response('', 404),
        ]);
    }

    /**
     * A site whose /sitemap.xml is an index naming two real sitemaps.
     *
     * One of the child locs carries a query string, because
     * `…/wp-sitemap-posts-post-1.xml?page=2` is real and used to slip through
     * an extension test and be crawled as a page.
     */
    private function fakeIndexedSite(bool $brokenChild = false): void
    {
        $pages = $this->healthyPage();

        Http::fake([
            'https://example.test/robots.txt' => Http::response(
                "User-agent: *\nAllow: /\nSitemap: https://example.test/sitemap.xml\n",
            ),
            'https://example.test/llms.txt' => Http::response(
                "# Cleaning Point\n\nA home cleaning service in Lisbon, booked online.\n",
            ),
            'https://example.test/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://example.test/sitemap-pages.xml</loc></sitemap>'
                .'<sitemap><loc>https://example.test/sitemap-posts.xml?page=1</loc></sitemap>'
                .'</sitemapindex>',
            ),
            'https://example.test/sitemap-pages.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://example.test/services</loc></url>'
                .'<url><loc>https://example.test/pricing</loc></url>'
                .'</urlset>',
            ),
            'https://example.test/sitemap-posts.xml*' => $brokenChild
                ? Http::response('', 500)
                : Http::response(
                    '<?xml version="1.0"?><urlset>'
                    .'<url><loc>https://example.test/journal/one</loc></url>'
                    .'</urlset>',
                ),
            'https://example.test/' => Http::response($pages),
            'https://example.test/services' => Http::response($pages),
            'https://example.test/pricing' => Http::response($pages),
            'https://example.test/journal/one' => Http::response($pages),
            '*' => Http::response('', 404),
        ]);
    }

    private function healthyPage(): string
    {
        return <<<'HTML'
            <html lang="en">
            <head>
                <title>End of tenancy cleaning in Lisbon</title>
                <meta name="description" content="A deposit-back clean for flats in Lisbon, priced per room and finished in a single day.">
                <meta name="viewport" content="width=device-width">
                <link rel="canonical" href="https://example.test/services">
                <script type="application/ld+json">{"@type":"Service","name":"Cleaning"}</script>
            </head>
            <body>
                <h1>End of tenancy cleaning</h1>
                <h2>What is included</h2>
            </body>
            </html>
            HTML;
    }

    /** Thin, and carrying the dead link, so the two faults land on one page. */
    private function thinPage(): string
    {
        return '<html lang="en"><head><title>Cleaning services in Lisbon area</title>'
            .'<link rel="canonical" href="https://example.test/broken"></head>'
            .'<body><h1>Cleaning</h1><a href="/gone">A link that no longer works</a></body></html>';
    }
}
