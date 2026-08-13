<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Audit\PageFacts;
use App\Audit\SiteCrawler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Turning HTML into facts.
 *
 * Extraction is tested apart from fetching, because it is where the awkwardness
 * lives: attribute order is not fixed, JSON-LD arrives in three shapes, and a
 * href can be relative, protocol-relative, a fragment or a `mailto:`. Every one
 * of those is real markup from real sites, and getting any of them wrong turns
 * into a finding about somebody's site that is actually a finding about us.
 */
final class SiteCrawlerTest extends TestCase
{
    #[Test]
    public function it_reads_the_head_whichever_order_the_attributes_are_in(): void
    {
        $facts = $this->extract(<<<'HTML'
            <html lang="pt-PT">
            <head>
                <title>Limpeza de fim de contrato</title>
                <meta content="Uma limpeza que devolve o depósito." name="description">
                <meta name="viewport" content="width=device-width">
                <link href="https://example.test/servicos" rel="canonical">
            </head>
            <body><h1>Limpeza</h1></body>
            </html>
            HTML);

        $this->assertSame('Limpeza de fim de contrato', $facts->title);
        // `content` before `name` is perfectly ordinary markup, and reading it
        // as a missing description would be our bug reported as their fault.
        $this->assertSame('Uma limpeza que devolve o depósito.', $facts->description);
        $this->assertSame('https://example.test/servicos', $facts->canonical);
        $this->assertSame('pt-PT', $facts->lang);
        $this->assertTrue($facts->hasViewport);
    }

    #[Test]
    public function it_finds_noindex_in_either_robots_directive(): void
    {
        foreach (['robots', 'googlebot'] as $agent) {
            $facts = $this->extract("<html><head><meta name=\"{$agent}\" content=\"noindex, follow\"></head></html>");

            $this->assertTrue($facts->isNoIndex, "A `{$agent}` noindex should be seen.");
        }
    }

    #[Test]
    public function headings_keep_document_order_so_a_skipped_level_is_detectable(): void
    {
        $facts = $this->extract('<h1>One</h1><h3>Three</h3><h2>Two</h2>');

        $this->assertSame([1, 3, 2], array_column($facts->headings, 'level'));
    }

    #[Test]
    public function it_reads_json_ld_types_from_a_graph_a_list_and_a_single_object(): void
    {
        $facts = $this->extract(<<<'HTML'
            <script type="application/ld+json">{"@type":"Organization","name":"A"}</script>
            <script type="application/ld+json">{"@graph":[{"@type":"WebPage"},{"@type":"BreadcrumbList"}]}</script>
            <script type="application/ld+json">{"@type":["Service","Product"]}</script>
            HTML);

        $this->assertEqualsCanonicalizing(
            ['Organization', 'WebPage', 'BreadcrumbList', 'Service', 'Product'],
            $facts->jsonLdTypes,
        );
        $this->assertFalse($facts->hasBrokenJsonLd);
    }

    #[Test]
    public function json_ld_that_does_not_parse_is_marked_rather_than_ignored(): void
    {
        // Invalid JSON-LD is silently discarded by every consumer, so the site
        // looks marked up to whoever wrote it and blank to everything else.
        $facts = $this->extract('<script type="application/ld+json">{"@type": "Organization",}</script>');

        $this->assertTrue($facts->hasBrokenJsonLd);
        $this->assertSame([], $facts->jsonLdTypes);
    }

    #[Test]
    public function it_separates_a_decorative_alt_from_a_missing_one(): void
    {
        $facts = $this->extract(
            '<img src="/a.png"><img src="/b.svg" alt=""><img src="/c.webp" alt="A room" width="10" height="10">',
        );

        $this->assertNull($facts->images[0]['alt'], 'No attribute at all is a missing alt.');
        $this->assertSame('', $facts->images[1]['alt'], 'alt="" is a decorative image.');
        $this->assertTrue($facts->images[2]['has_dimensions']);
        $this->assertSame('webp', $facts->images[2]['format']);
    }

    #[Test]
    public function a_data_uri_image_is_not_a_file_being_served(): void
    {
        $facts = $this->extract('<img src="data:image/gif;base64,R0lGOD">');

        $this->assertSame([], $facts->images);
    }

    #[Test]
    public function links_are_resolved_to_the_origin_and_anything_that_is_not_a_page_is_dropped(): void
    {
        $facts = $this->extract(
            '<a href="/services">a</a>'
            .'<a href="pricing">b</a>'
            .'<a href="//example.test/about">c</a>'
            .'<a href="https://example.test/journal#top">d</a>'
            .'<a href="https://elsewhere.test/x">e</a>'
            .'<a href="mailto:hi@example.test">f</a>'
            .'<a href="#section">g</a>',
            'https://example.test/guides/index.html',
        );

        $this->assertSame([
            'https://example.test/services',
            // Relative to the current directory, not to the root.
            'https://example.test/guides/pricing',
            'https://example.test/about',
            // The fragment addresses a place on a page, not a page.
            'https://example.test/journal',
        ], $facts->internalLinks);
    }

    #[Test]
    public function a_hostname_that_merely_starts_with_the_origin_is_not_internal(): void
    {
        $facts = $this->extract(
            '<a href="https://example.test/ok">a</a>'
            .'<a href="https://example.testing/x">b</a>'
            .'<a href="https://example.test.cdn.net/y">c</a>',
            'https://example.test/services',
        );

        // A prefix test alone lets both of the last two through as internal.
        // VerifyLinks then fetches them under the origin guard, the guard
        // refuses, and a live external link is reported to the customer as
        // their own dead page.
        $this->assertSame(['https://example.test/ok'], $facts->internalLinks);
    }

    #[Test]
    public function a_link_repeated_across_a_navigation_is_counted_once(): void
    {
        $facts = $this->extract(
            '<a href="/services">a</a><a href="/services">b</a><a href="/services?ref=footer">c</a>',
        );

        $this->assertSame([
            'https://example.test/services',
            'https://example.test/services?ref=footer',
        ], $facts->internalLinks);
    }

    private function extract(string $html, string $url = 'https://example.test/services'): PageFacts
    {
        return app(SiteCrawler::class)->extract($url, $html);
    }
}
