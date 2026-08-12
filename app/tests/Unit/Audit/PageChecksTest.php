<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Audit\Checks\CanonicalUrlCheck;
use App\Audit\Checks\HeadingStructureCheck;
use App\Audit\Checks\ImageOptimizationCheck;
use App\Audit\Checks\IndexabilityCheck;
use App\Audit\Checks\JsonLdSchemaCheck;
use App\Audit\Checks\MetaDescriptionCheck;
use App\Audit\Checks\MetaTitleCheck;
use App\Audit\Checks\PageWeightCheck;
use App\Audit\PageFacts;
use App\Enums\AuditSeverity;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page checks, one fixture each.
 *
 * Every check is a pure function of {@see PageFacts}, which is the property the
 * whole design is arranged around — so these need no database, no network and
 * no pipeline. What they are really testing is the judgement in each check:
 * where the boundary between a fault and a preference sits, and which faults are
 * severe enough to be worth an operator's morning.
 */
final class PageChecksTest extends TestCase
{
    // ------------------------------------------------------- indexability

    #[Test]
    public function a_sitemap_page_that_404s_is_the_most_serious_thing_the_audit_can_say(): void
    {
        $findings = (new IndexabilityCheck)->run($this->facts(['status_code' => 404]));

        $this->assertCount(1, $findings);
        $this->assertSame(AuditSeverity::High, $findings[0]->severity);
    }

    #[Test]
    public function a_page_asking_not_to_be_indexed_while_the_sitemap_invites_crawlers_is_a_contradiction(): void
    {
        $findings = (new IndexabilityCheck)->run($this->facts(['is_noindex' => true]));

        $this->assertCount(1, $findings);
        $this->assertSame(AuditSeverity::High, $findings[0]->severity);
    }

    #[Test]
    public function a_healthy_page_raises_nothing(): void
    {
        $this->assertSame([], (new IndexabilityCheck)->run($this->facts()));
    }

    // ------------------------------------------------------------- titles

    #[Test]
    public function a_missing_title_is_high_and_a_long_one_is_only_untidy(): void
    {
        $check = new MetaTitleCheck;

        $missing = $check->run($this->facts(['title' => null]));
        $long = $check->run($this->facts(['title' => str_repeat('a', 90)]));

        $this->assertSame(AuditSeverity::High, $missing[0]->severity);
        $this->assertSame(AuditSeverity::Low, $long[0]->severity);
        // The measured length reaches the screen: "too long" without a number
        // is not something anybody can act on.
        $this->assertSame(90, $long[0]->detail['length']);
    }

    #[Test]
    public function a_title_inside_the_range_is_left_alone(): void
    {
        $this->assertSame([], (new MetaTitleCheck)->run($this->facts([
            'title' => 'End of tenancy cleaning in Lisbon',
        ])));
    }

    #[Test]
    public function a_missing_description_costs_less_than_a_missing_title(): void
    {
        $title = (new MetaTitleCheck)->run($this->facts(['title' => null]));
        $description = (new MetaDescriptionCheck)->run($this->facts(['description' => null]));

        $this->assertGreaterThan(
            $description[0]->severity->penalty(),
            $title[0]->severity->penalty(),
            'A page with no title cannot be found; a page with no description is merely described badly.',
        );
    }

    // ---------------------------------------------------------- canonicals

    #[Test]
    public function a_canonical_pointing_at_another_page_is_the_page_asking_to_be_ignored(): void
    {
        $findings = (new CanonicalUrlCheck)->run($this->facts([
            'url' => 'https://example.test/services',
            'canonical' => 'https://example.test/pricing',
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame(AuditSeverity::High, $findings[0]->severity);
    }

    #[Test]
    public function a_self_referencing_canonical_is_correct_however_it_is_spelled(): void
    {
        $check = new CanonicalUrlCheck;

        // A trailing slash, a differently-cased host and a query string are all
        // normalisation choices rather than mistakes. Reporting them would bury
        // the one case above that actually matters.
        foreach ([
            'https://example.test/services/',
            'https://EXAMPLE.test/services',
            'https://example.test/services?ref=nav',
        ] as $canonical) {
            $this->assertSame([], $check->run($this->facts([
                'url' => 'https://example.test/services',
                'canonical' => $canonical,
            ])), "`{$canonical}` should read as the page itself.");
        }
    }

    #[Test]
    public function a_relative_canonical_is_reported_without_being_treated_as_a_redirect(): void
    {
        $findings = (new CanonicalUrlCheck)->run($this->facts([
            'url' => 'https://example.test/services',
            'canonical' => '/services',
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame(AuditSeverity::Medium, $findings[0]->severity);
    }

    // ------------------------------------------------------------ outline

    #[Test]
    public function a_page_with_no_h1_and_a_page_with_three_are_different_faults(): void
    {
        $check = new HeadingStructureCheck;

        $none = $check->run($this->facts(['headings' => []]));
        $several = $check->run($this->facts(['headings' => [
            ['level' => 1, 'text' => 'One'],
            ['level' => 1, 'text' => 'Two'],
        ]]));

        $this->assertSame(AuditSeverity::Medium, $none[0]->severity);
        $this->assertSame(AuditSeverity::Low, $several[0]->severity);
    }

    #[Test]
    public function only_the_first_skipped_heading_level_is_reported(): void
    {
        // A page with a broken pattern breaks it in every section, and eight
        // findings about one mistake is a screen nobody reads.
        $findings = (new HeadingStructureCheck)->run($this->facts(['headings' => [
            ['level' => 1, 'text' => 'Title'],
            ['level' => 4, 'text' => 'First'],
            ['level' => 2, 'text' => 'Back up'],
            ['level' => 5, 'text' => 'Second'],
        ]]));

        $this->assertCount(1, $findings, 'One h1 and two skips should report one skip.');
        $this->assertSame(4, $findings[0]->detail['to']);
    }

    // -------------------------------------------------------- structured data

    #[Test]
    public function structured_data_that_does_not_parse_is_reported_as_its_own_fault(): void
    {
        $broken = (new JsonLdSchemaCheck)->run($this->facts([
            'json_ld_types' => [],
            'has_broken_json_ld' => true,
        ]));

        $this->assertCount(1, $broken);
        $this->assertStringContainsString('does not parse', $broken[0]->summary);
    }

    #[Test]
    public function a_page_with_a_declared_type_is_left_alone(): void
    {
        $this->assertSame([], (new JsonLdSchemaCheck)->run($this->facts([
            'json_ld_types' => ['LocalBusiness'],
        ])));
    }

    // -------------------------------------------------------------- images

    #[Test]
    public function images_are_counted_rather_than_listed_one_finding_at_a_time(): void
    {
        $findings = (new ImageOptimizationCheck)->run($this->facts([
            'images' => [
                ['src' => '/a.png', 'alt' => null, 'has_dimensions' => true, 'format' => 'png'],
                ['src' => '/b.png', 'alt' => null, 'has_dimensions' => true, 'format' => 'png'],
                ['src' => '/c.webp', 'alt' => 'A room', 'has_dimensions' => true, 'format' => 'webp'],
            ],
        ]));

        $summaries = array_map(static fn ($finding): string => $finding->summary, $findings);

        $this->assertCount(2, $findings, 'One finding per fault, not one per image.');
        $this->assertStringContainsString('2 images have no alt text', $summaries[0]);
        $this->assertStringContainsString('2 images are served in a dated format', $summaries[1]);
    }

    #[Test]
    public function the_number_of_images_is_the_real_one_however_many_are_named(): void
    {
        $images = [];

        for ($i = 0; $i < 50; $i++) {
            $images[] = ['src' => "/photo-{$i}.png", 'alt' => null, 'has_dimensions' => true, 'format' => 'webp'];
        }

        $findings = (new ImageOptimizationCheck)->run($this->facts(['images' => $images]));

        // Counting the capped evidence list reported every such page as having
        // exactly ten — a wrong number plausible enough that nobody would ever
        // go and check it.
        $this->assertStringContainsString('50 images have no alt text', $findings[0]->summary);
        $this->assertCount(10, $findings[0]->detail['images'], 'The evidence stays capped.');
    }

    #[Test]
    public function one_source_repeated_across_a_page_is_named_once_but_counted_each_time(): void
    {
        $findings = (new ImageOptimizationCheck)->run($this->facts([
            'images' => [
                ['src' => '/logo.png', 'alt' => null, 'has_dimensions' => true, 'format' => 'webp'],
                ['src' => '/logo.png', 'alt' => null, 'has_dimensions' => true, 'format' => 'webp'],
                ['src' => '/hero.png', 'alt' => null, 'has_dimensions' => true, 'format' => 'webp'],
            ],
        ]));

        // Three images are wrong on the page; two files need fixing.
        $this->assertStringContainsString('3 images have no alt text', $findings[0]->summary);
        $this->assertSame(['/logo.png', '/hero.png'], $findings[0]->detail['images']);
    }

    #[Test]
    public function an_empty_alt_is_a_decorative_image_and_not_a_missing_one(): void
    {
        // `alt=""` is the correct marking for decoration, and punishing a site
        // for doing the right thing would teach it to stop.
        $this->assertSame([], (new ImageOptimizationCheck)->run($this->facts([
            'images' => [
                ['src' => '/divider.svg', 'alt' => '', 'has_dimensions' => true, 'format' => 'svg'],
            ],
        ])));
    }

    // ----------------------------------------------------------- responses

    #[Test]
    public function a_very_slow_server_costs_more_than_a_merely_slow_one(): void
    {
        $check = new PageWeightCheck;

        $slow = $check->run($this->facts(['response_ms' => 2_500]));
        $glacial = $check->run($this->facts(['response_ms' => 6_000]));

        $this->assertSame(AuditSeverity::Low, $slow[0]->severity);
        $this->assertSame(AuditSeverity::Medium, $glacial[0]->severity);
    }

    #[Test]
    public function a_quick_light_page_raises_nothing(): void
    {
        $this->assertSame([], (new PageWeightCheck)->run($this->facts([
            'response_ms' => 180,
            'html_bytes' => 42_000,
        ])));
    }

    /**
     * A healthy page, with whatever is under test overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function facts(array $overrides = []): PageFacts
    {
        return PageFacts::fromArray([
            'url' => 'https://example.test/services',
            'status_code' => 200,
            'response_ms' => 210,
            'html_bytes' => 38_000,
            'title' => 'End of tenancy cleaning in Lisbon',
            'description' => 'A deposit-back clean for flats in Lisbon, priced per room and finished in a day.',
            'canonical' => 'https://example.test/services',
            'lang' => 'en',
            'has_viewport' => true,
            'is_noindex' => false,
            'headings' => [
                ['level' => 1, 'text' => 'End of tenancy cleaning'],
                ['level' => 2, 'text' => 'What is included'],
            ],
            'json_ld_types' => ['Service'],
            'has_broken_json_ld' => false,
            'images' => [],
            'internal_links' => [],
            ...$overrides,
        ]);
    }
}
