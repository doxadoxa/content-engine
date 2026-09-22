<?php

declare(strict_types=1);

namespace Tests\Feature\Opportunities;

use App\Enums\SitePageKind;
use App\Feedback\Measurements\ReadStatus;
use App\Models\MeasurementRead;
use App\Models\PageMetric;
use App\Models\PageOpportunity;
use App\Models\PageOpportunityScan;
use App\Models\PageQueryMetric;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Opportunities\DiagnoseOpportunities;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PageOpportunitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-15 18:00:00', 'UTC'));
    }

    #[Test]
    public function a_read_does_not_generate_work_and_an_empty_scan_is_an_explicit_valid_result(): void
    {
        [$owner, $project] = $this->owner();
        $this->page('Deep cleaning', 'Our deep cleaning price starts at €80. The checklist includes floors, bathrooms and kitchen surfaces. Request a quote for your own home.');
        $this->actingAs($owner)->get('/plan')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('opportunities/index')->where('scan', null)->has('opportunities', 0));
        $this->assertSame(0, PageOpportunityScan::query()->count());
        $this->assertSame(0, app(DiagnoseOpportunities::class)->refresh($project));
        $this->assertSame(1, PageOpportunityScan::query()->count());
        $this->get('/plan')->assertInertia(fn (AssertableInertia $page) => $page->has('scan')->has('opportunities', 0));
    }

    #[Test]
    public function a_missing_buyer_fact_can_be_reviewed_without_inventing_search_measurements(): void
    {
        [, $project] = $this->owner();
        $this->page('Deep cleaning', 'A complete deep cleaning service for homes in Lisbon. Our trained team carefully cleans each room and leaves every surface ready for your next visit.');
        app(DiagnoseOpportunities::class)->refresh($project);
        $opportunity = PageOpportunity::query()->sole();
        $this->assertSame('missing_business_fact', $opportunity->kind);
        $this->assertSame('low', $opportunity->confidence);
        $this->assertNull($opportunity->evidence_snapshot['search']['current']['clicks']);
        $this->assertNotEmpty($opportunity->missing_fact_questions);
        $this->assertSame([], $opportunity->evidence_snapshot['confirmed_facts']);
        $this->assertSame(0, MeasurementRead::query()->count());
    }

    #[Test]
    public function decline_needs_two_observed_windows_and_relevant_queries_with_fresh_reads(): void
    {
        [, $project] = $this->owner();
        $page = $this->page('Deep cleaning prices in Lisbon', 'Our price starts at €80. The included checklist covers rooms, floors and surfaces. Get a quote before booking a deep cleaning visit.');
        $this->measure($page, previousClicks: 20, currentClicks: 10, query: 'deep cleaning lisbon');
        app(DiagnoseOpportunities::class)->refresh($project);
        $opportunity = PageOpportunity::query()->sole();
        $this->assertSame('declining_performance', $opportunity->kind);
        $this->assertSame(20, $opportunity->evidence_snapshot['search']['previous']['clicks']);
        $this->assertSame(10, $opportunity->evidence_snapshot['search']['current']['clicks']);
        $this->assertStringContainsString('cause is not established', $opportunity->diagnosed_issue);
        MeasurementRead::query()->where('source', 'gsc_queries')->update(['status' => ReadStatus::Partial]);
        app(DiagnoseOpportunities::class)->refresh($project);
        $this->assertSame('withdrawn', $opportunity->fresh()->status);
    }

    #[Test]
    public function sparse_missing_or_unrelated_search_does_not_trigger_a_performance_rewrite(): void
    {
        [, $project] = $this->owner();
        $page = $this->page('Deep cleaning prices in Lisbon', 'Our price starts at €80. The included checklist covers rooms, floors and surfaces. Get a quote before booking a deep cleaning visit.');
        $this->measure($page, previousClicks: 2, currentClicks: 0, query: 'deep cleaning lisbon');
        app(DiagnoseOpportunities::class)->refresh($project);
        $this->assertSame(0, PageOpportunity::query()->count());
        PageMetric::query()->where('measured_on', '2026-08-01')->update(['clicks' => 30]);
        PageQueryMetric::query()->update(['query' => 'curtain installation stockport']);
        app(DiagnoseOpportunities::class)->refresh($project);
        $this->assertSame(0, PageOpportunity::query()->count());
    }

    #[Test]
    public function an_answer_gap_requires_observed_query_intent_and_a_specific_possible_omission(): void
    {
        [, $project] = $this->owner();
        $page = $this->page('Deep cleaning in Lisbon', 'Our deep cleaning team helps Lisbon homes regain a fresh start. We carefully clean each room and leave it ready for your next visit.');
        $this->measure($page, previousClicks: 0, currentClicks: 1, query: 'deep cleaning cost', queryImpressions: 35);
        app(DiagnoseOpportunities::class)->refresh($project);
        $opportunity = PageOpportunity::query()->sole();
        $this->assertSame('answer_gap', $opportunity->kind);
        $this->assertStringContainsString('35 times', $opportunity->diagnosed_issue);
        $this->assertSame('low', $opportunity->confidence);
    }

    #[Test]
    public function only_five_priorities_are_shown_and_overlap_never_crosses_locale_or_tenant(): void
    {
        [$owner, $project] = $this->owner();
        $seedPages = [];
        for ($i = 0; $i < 7; $i++) {
            $seedPages[] = $this->page('Lisbon deep cleaning '.$i, 'Our local team cleans your home carefully and reliably. Book a deep cleaning visit and enjoy clean rooms for the whole family.');
        }
        $foreignLocale = $this->page('Lisbon deep cleaning', 'Our local team cleans your home carefully and reliably. Book a deep cleaning visit and enjoy clean rooms for the whole family.', 'pt');
        // All titles tie after numeric tokens are removed. Keep this locale
        // fixture inside the five-result cap regardless of random page slugs.
        $knownUntracked = SitePage::factory()->create(['title' => 'Lisbon deep cleaning 0', 'url' => 'https://example.com/en/000-existing-cleaning-guide', 'locale' => null, 'tracked_at' => null]);
        app(DiagnoseOpportunities::class)->refresh($project);
        $enOpportunity = PageOpportunity::query()->where('site_page_id', $seedPages[0]->id)->firstOrFail();
        $this->assertNotEmpty($enOpportunity->overlap_page_ids);
        $this->assertNotContains($foreignLocale->id, $enOpportunity->overlap_page_ids);
        $this->assertContains($knownUntracked->id, $enOpportunity->overlap_page_ids);
        $this->actingAs($owner)->get('/plan')->assertInertia(fn (AssertableInertia $page) => $page->has('opportunities', 5));
        app(CurrentProject::class)->run(Project::factory()->create(), function (): void {
            $this->assertSame(0, PageOpportunity::query()->count());
        });
    }

    #[Test]
    public function dismissal_survives_refresh_and_proposed_evidence_is_not_rewritten_by_new_metrics(): void
    {
        [$owner, $project] = $this->owner();
        $page = $this->page('Deep cleaning in Lisbon', 'Our local team cleans your home carefully and reliably. Book a deep cleaning visit and enjoy clean rooms for the whole family.');
        app(DiagnoseOpportunities::class)->refresh($project);
        $opportunity = PageOpportunity::query()->sole();
        $this->actingAs($owner)->post('/opportunities/'.$opportunity->id.'/dismiss', ['reason' => 'The quote calculator already explains this.'])->assertRedirect();
        app(DiagnoseOpportunities::class)->refresh($project);
        $this->assertSame('dismissed', $opportunity->fresh()->status);
        $this->post('/opportunities/'.$opportunity->id.'/reconsider')->assertRedirect();
        app(DiagnoseOpportunities::class)->refresh($project);
        $this->assertSame('open', $opportunity->fresh()->status);
        $this->assertSame('The quote calculator already explains this.', $opportunity->fresh()->dismissal_reason);
        $opportunity->refresh()->update(['status' => 'proposed']);
        $evidence = $opportunity->fresh()->evidence_snapshot;
        $this->measure($page, previousClicks: 1, currentClicks: 1, query: 'deep cleaning');
        app(DiagnoseOpportunities::class)->refresh($project);
        $this->assertSame($evidence, $opportunity->fresh()->evidence_snapshot);
    }

    #[Test]
    public function stale_snapshots_and_paused_pages_do_not_create_new_work(): void
    {
        [, $project] = $this->owner();
        $page = $this->page('Deep cleaning', 'Our local team cleans your home carefully and reliably. Book a deep cleaning visit and enjoy clean rooms for the whole family.', capturedAt: now()->subDays(31));
        $this->assertSame(0, app(DiagnoseOpportunities::class)->refresh($project));
        $page->update(['tracked_at' => null]);
        $this->assertSame(0, app(DiagnoseOpportunities::class)->refresh($project));
    }

    #[Test]
    public function only_owners_can_change_the_plan_and_foreign_opportunities_are_hidden(): void
    {
        [$owner, $project] = $this->owner();
        $this->page('Deep cleaning', 'Our local team cleans your home carefully and reliably. Book a deep cleaning visit and enjoy clean rooms for the whole family.');
        app(DiagnoseOpportunities::class)->refresh($project);
        $opportunity = PageOpportunity::query()->sole();
        $operator = User::factory()->create();
        $operator->projects()->attach($project, ['role' => 'operator']);
        $this->actingAs($operator)->get('/plan')->assertOk();
        $this->post('/plan/refresh')->assertForbidden();
        $this->post('/opportunities/'.$opportunity->id.'/dismiss', ['reason' => 'No'])->assertForbidden();
        $foreign = User::factory()->create();
        $foreign->projects()->attach(Project::factory()->create(), ['role' => 'owner']);
        $this->actingAs($foreign)->post('/opportunities/'.$opportunity->id.'/dismiss', ['reason' => 'Wrong tenant'])->assertNotFound();
    }

    #[Test]
    public function the_database_refuses_a_cross_project_page_reference(): void
    {
        [, $project] = $this->owner();
        $foreignPage = app(CurrentProject::class)->run(Project::factory()->create(), fn () => $this->page('Deep cleaning', 'Our local team cleans your home carefully and reliably. Book a deep cleaning visit and enjoy clean rooms for the whole family.'));
        $this->page('Other cleaning', 'Our local team cleans your home carefully and reliably. Book a deep cleaning visit and enjoy clean rooms for the whole family.');
        app(DiagnoseOpportunities::class)->refresh($project);
        $this->expectException(QueryException::class);
        PageOpportunity::query()->sole()->update(['site_page_id' => $foreignPage->id]);
    }

    #[Test]
    public function manually_flagged_conflicting_text_stays_reviewable_without_becoming_a_fact(): void
    {
        [$owner, $project] = $this->owner();
        $body = 'Deep cleaning starts at €80 and includes the oven interior. Optional extras include oven and appliance cleaning. Ask our team to confirm your booking.';
        $page = $this->page('Deep cleaning', $body);
        $payload = ['site_page_id' => $page->id, 'snapshot_id' => $page->latestSnapshot->id, 'quoted_excerpt' => 'includes the oven interior', 'additional_excerpt' => 'Optional extras include oven and appliance cleaning', 'question' => 'Is oven cleaning included in the base service, and what does the extra cover?'];
        $this->actingAs($owner)->post('/opportunities', $payload)->assertRedirect('/plan');
        $this->post('/opportunities', $payload)->assertRedirect('/plan');
        $opportunity = PageOpportunity::query()->sole();
        $this->assertSame('owner', $opportunity->evidence_snapshot['diagnosis_mode']);
        $this->assertSame([], $opportunity->evidence_snapshot['confirmed_facts']);
        app(DiagnoseOpportunities::class)->refresh($project);
        $this->assertSame('open', $opportunity->fresh()->status);
        $this->assertSame(1, PageOpportunity::query()->count());
        $this->assertDatabaseCount('business_facts', 0);
        $this->post('/opportunities/'.$opportunity->id.'/dismiss', ['reason' => 'We will review the inclusion rule next week.'])->assertRedirect();
        $this->post('/opportunities/'.$opportunity->id.'/reconsider')->assertRedirect();
        app(DiagnoseOpportunities::class)->refresh($project);
        $this->assertSame('open', $opportunity->fresh()->status);
        $this->assertSame('We will review the inclusion rule next week.', $opportunity->fresh()->dismissal_reason);
    }

    #[Test]
    public function a_manual_question_cannot_attach_invented_evidence_or_an_old_snapshot(): void
    {
        [$owner] = $this->owner();
        $page = $this->page('Deep cleaning', 'Deep cleaning starts at €80 and includes the oven interior. Optional extras include oven and appliance cleaning. Ask our team to confirm your booking.');
        $payload = ['site_page_id' => $page->id, 'snapshot_id' => $page->latestSnapshot->id, 'quoted_excerpt' => 'We promise a 100% refund for any reason', 'question' => 'Does the refund apply to every booking?'];
        $this->actingAs($owner)->post('/opportunities', $payload)->assertSessionHasErrors('quoted_excerpt');
        $payload['quoted_excerpt'] = 'includes the oven interior';
        $payload['snapshot_id'] = '01m2j78gk7pq5wkpw1x6bmnsa7';
        $this->post('/opportunities', $payload)->assertSessionHasErrors('site_page_id');
        $this->assertSame(0, PageOpportunity::query()->count());
    }

    /** @return array{User, Project} */
    private function owner(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['website_url' => 'https://example.com', 'default_locale' => 'en']);
        $owner->projects()->attach($project, ['role' => 'owner']);
        app(CurrentProject::class)->set($project);

        return [$owner, $project];
    }

    private function page(string $title, string $body, string $locale = 'en', ?Carbon $capturedAt = null): SitePage
    {
        $url = 'https://example.com/'.$locale.'/'.fake()->unique()->slug();
        $page = SitePage::factory()->create(['url' => $url, 'canonical_url' => $url, 'canonical_hash' => hash('sha256', $url), 'title' => $title, 'page_kind' => SitePageKind::Commercial, 'tracked_at' => now(), 'locale' => $locale]);
        PageSnapshot::query()->create(['site_page_id' => $page->id, 'source_kind' => 'public', 'source_url' => $url, 'captured_at' => $capturedAt ?? now(), 'revision' => 'public:1', 'content_hash' => hash('sha256', $body), 'fields' => ['title' => $title, 'description' => '', 'body_text' => $body, 'body_html' => '<p>'.$body.'</p>'], 'editable_fields' => [], 'metadata' => ['canonical_url' => $url, 'locale' => $locale]]);

        return $page;
    }

    private function measure(SitePage $page, int $previousClicks, int $currentClicks, string $query, int $queryImpressions = 25): void
    {
        $read = MeasurementRead::query()->create(['source' => 'gsc_pages', 'window_from' => '2026-07-19', 'window_to' => '2026-09-12', 'status' => ReadStatus::Complete, 'row_count' => 2, 'started_at' => now(), 'finished_at' => now()]);
        foreach (['2026-08-01' => $previousClicks, '2026-09-01' => $currentClicks] as $day => $clicks) {
            PageMetric::query()->create(['site_page_id' => $page->id, 'measurement_read_id' => $read->id, 'measured_on' => $day, 'impressions' => 250, 'clicks' => $clicks, 'position_tenths' => 90]);
        }
        $queries = MeasurementRead::query()->create(['source' => 'gsc_queries', 'window_from' => '2026-07-19', 'window_to' => '2026-09-12', 'status' => ReadStatus::Complete, 'row_count' => 1, 'started_at' => now(), 'finished_at' => now()]);
        PageQueryMetric::query()->create(['site_page_id' => $page->id, 'measurement_read_id' => $queries->id, 'measured_on' => '2026-09-01', 'query' => $query, 'query_hash' => hash('sha256', $query), 'impressions' => $queryImpressions, 'clicks' => $currentClicks, 'position_tenths' => 90]);
    }
}
