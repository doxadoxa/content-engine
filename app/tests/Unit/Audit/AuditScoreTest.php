<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Audit\AuditScore;
use App\Audit\CheckFinding;
use App\Enums\AuditCheckGroup;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How findings become numbers.
 *
 * The distinction this file exists to hold is null versus zero. Most
 * installations have no PageSpeed key, so performance is unmeasured on most
 * sweeps — and if an unmeasured group scored zero instead of nothing, every one
 * of those sites would be reported as a fifth worse than it is, permanently and
 * for a fact about our configuration rather than about their site.
 */
final class AuditScoreTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function a_page_starts_at_a_hundred_and_loses_each_findings_penalty(): void
    {
        $score = app(AuditScore::class)->forPage([
            CheckFinding::high('gone'),
            CheckFinding::low('untidy'),
        ]);

        $this->assertSame(100 - 20 - 3, $score);
    }

    #[Test]
    public function a_page_cannot_score_below_zero_however_many_things_are_wrong(): void
    {
        $findings = array_fill(0, 20, CheckFinding::high('gone'));

        $this->assertSame(0, app(AuditScore::class)->forPage($findings));
    }

    #[Test]
    public function a_clean_sweep_scores_a_hundred_everywhere_that_was_measured(): void
    {
        $audit = $this->audit(pages: 5);

        $scores = app(AuditScore::class)->forAudit($audit);

        $this->assertSame(100, $scores['seo']);
        $this->assertSame(100, $scores['geo']);
        $this->assertSame(100, $scores['health']);
    }

    #[Test]
    public function two_bad_pages_out_of_a_hundred_do_not_make_a_bad_site(): void
    {
        $audit = $this->audit(pages: 100);

        $pages = $audit->pages()->limit(2)->get();

        foreach ($pages as $page) {
            $this->issue($audit, 'meta_title', 'high', (string) $page->getKey());
        }

        $scores = app(AuditScore::class)->forAudit($audit);

        // Averaged over every page crawled rather than over the ones with
        // findings: averaging the two would report this site at 80.
        $this->assertGreaterThanOrEqual(99, $scores['seo']);
    }

    #[Test]
    public function a_site_wide_finding_costs_more_than_the_same_finding_on_one_page(): void
    {
        $sitewide = $this->audit(pages: 20);
        $this->issue($sitewide, 'robots_txt', 'high', null);

        $perPage = $this->audit(pages: 20);
        $this->issue($perPage, 'meta_title', 'high', (string) $perPage->pages()->first()?->getKey());

        $scorer = app(AuditScore::class);

        $this->assertLessThan(
            $scorer->forAudit($perPage)['seo'],
            $scorer->forAudit($sitewide)['seo'],
            'A `Disallow: /` is true of every page at once and must not average away as one row in twenty.',
        );
    }

    #[Test]
    public function an_unmeasured_group_is_dropped_from_the_headline_rather_than_scored_zero(): void
    {
        $audit = $this->audit(pages: 0);

        $scores = app(AuditScore::class)->forAudit($audit);

        // Nothing was crawled, so no page check ran. The site checks did.
        $this->assertNull($scores['performance'], 'Performance has no site-level checks behind it.');
        $this->assertNotNull($scores['health']);
        $this->assertSame(
            100,
            $scores['health'],
            'A headline renormalised over the groups that ran, not scored out of a total that includes one that did not.',
        );
    }

    #[Test]
    public function a_site_with_no_pagespeed_key_can_still_reach_a_hundred(): void
    {
        $audit = $this->audit(pages: 5);

        $scores = app(AuditScore::class)->forAudit($audit);

        // Without renormalisation the missing fifth of the weight would cap
        // every keyless installation at 80 — a number nobody could ever
        // improve, on a screen whose whole purpose is telling you what to
        // improve.
        $this->assertSame(100, $scores['health']);
    }

    #[Test]
    public function a_mostly_dead_site_is_not_reported_as_flawless_on_what_was_never_read(): void
    {
        // Nine pages of ten answering 404 is an ordinary shape after a site
        // restructure. Those nine run only the indexability check, so they have
        // no title to be missing and no image to be unsized — and divided by
        // ten they used to count as clean, putting "LLM optimization" at 99 for
        // a site that is ninety per cent gone.
        $audit = $this->audit(pages: 1);

        for ($i = 0; $i < 9; $i++) {
            $dead = SiteAuditPage::factory()->create([
                'site_audit_id' => $audit->getKey(),
                'url' => "https://example.test/dead-{$i}",
                'status_code' => 404,
            ]);

            $this->issue($audit, 'indexability', 'high', (string) $dead->getKey());
        }

        $scores = app(AuditScore::class)->forAudit($audit);

        // SEO is averaged over all ten, because indexability — the check that
        // fired on the nine — is an SEO check and did look at them. Nine pages
        // at 100 − 20 and one at 100 is 82: a visible drop, next to nine high
        // findings in the table.
        $this->assertSame(82, $scores['seo']);

        // Geo is the one the fix is really about. It saw one page, and that
        // page is fine — so 100, over a denominator of one. Averaged over ten
        // it read 99 and meant nothing, because nine of the ten were never
        // looked at by a single Geo check.
        $this->assertSame(
            100,
            $scores['geo'],
            'Geo is averaged over the page it could read, not over ten it could not.',
        );
    }

    #[Test]
    public function a_group_is_averaged_over_the_pages_its_own_checks_looked_at(): void
    {
        $audit = $this->audit(pages: 2);

        $dead = SiteAuditPage::factory()->create([
            'site_audit_id' => $audit->getKey(),
            'url' => 'https://example.test/dead',
            'status_code' => 500,
        ]);

        $this->issue($audit, 'indexability', 'high', (string) $dead->getKey());
        $this->issue($audit, 'json_ld_schema', 'medium', (string) $audit->pages()->first()?->getKey());

        $scores = app(AuditScore::class)->forAudit($audit);

        // Geo saw two readable pages, one of which is missing structured data:
        // (100 + 92) / 2. The unreadable third page is not in the denominator.
        $this->assertSame(96, $scores['geo']);
    }

    #[Test]
    public function lighthouse_readings_outweigh_our_own_timings_without_replacing_them(): void
    {
        $audit = $this->audit(pages: 3);

        $audit->pages()->first()?->forceFill(['speed' => ['score' => 40]])->save();

        $scorer = app(AuditScore::class);

        $blended = $scorer->blendPageSpeed(100, $audit->pages()->whereNotNull('speed')->get());

        $this->assertGreaterThan(40, $blended);
        $this->assertLessThan(100, $blended);
    }

    #[Test]
    public function a_sweep_with_no_lighthouse_readings_keeps_whatever_the_findings_said(): void
    {
        $audit = $this->audit(pages: 3);

        $this->assertSame(
            88,
            app(AuditScore::class)->blendPageSpeed(88, $audit->pages()->whereNotNull('speed')->get()),
        );
        $this->assertNull(
            app(AuditScore::class)->blendPageSpeed(null, $audit->pages()->whereNotNull('speed')->get()),
        );
    }

    #[Test]
    public function every_group_the_screen_shows_has_a_weight_that_sums_to_one(): void
    {
        $total = array_sum(array_map(
            static fn (AuditCheckGroup $group): float => $group->weight(),
            AuditCheckGroup::cases(),
        ));

        $this->assertEqualsWithDelta(1.0, $total, 0.0001);
    }

    private function audit(int $pages): SiteAudit
    {
        $audit = SiteAudit::factory()->create(['pages_crawled' => $pages]);

        for ($i = 0; $i < $pages; $i++) {
            SiteAuditPage::factory()->create([
                'site_audit_id' => $audit->getKey(),
                'url' => "https://example.test/page-{$i}",
                'issues_count' => 0,
                'speed' => null,
            ]);
        }

        return $audit;
    }

    private function issue(SiteAudit $audit, string $check, string $severity, ?string $pageId): void
    {
        SiteAuditIssue::factory()->create([
            'site_audit_id' => $audit->getKey(),
            'site_audit_page_id' => $pageId,
            'check_key' => $check,
            'severity' => $severity,
        ]);
    }
}
