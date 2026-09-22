<?php

declare(strict_types=1);

namespace Tests\Feature\Measurements;

use App\Enums\SitePageKind;
use App\Events\PageChangeVerified;
use App\Events\PageProposalAccepted;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\FakeSearchConsole;
use App\Feedback\Followups\CaptureChangeReviews;
use App\Feedback\Followups\ChangeFollowupReport;
use App\Feedback\Followups\ObservationWindow;
use App\Feedback\Followups\PinChangeBaseline;
use App\Feedback\Followups\StartChangeFollowups;
use App\Feedback\Measurements\PagePerformance;
use App\Feedback\Measurements\ReadResult;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SearchRow;
use App\Feedback\Measurements\SynchronizePageMeasurements;
use App\Models\Channel;
use App\Models\MeasurementRead;
use App\Models\PageChangeBaseline;
use App\Models\PageChangeFollowup;
use App\Models\PageChangeReview;
use App\Models\PageMetric;
use App\Models\PageOpportunity;
use App\Models\PageProposal;
use App\Models\PageProposalRevision;
use App\Models\PagePublication;
use App\Models\PagePublicationOperation;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\PurchaseRecord;
use App\Models\PurchaseSource;
use App\Models\SitePage;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PageChangeFollowupTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 18:00:00', 'UTC'));
        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function approval_event_pins_actual_evidence_once_and_later_restatements_cannot_rewrite_it(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $window = ObservationWindow::baseline(CarbonImmutable::now(), 28);
        $this->observations([$page], $window, 10);
        Event::dispatch(new PageProposalAccepted($this->project->id, $proposal->id, $revision->id));
        $baseline = PageChangeBaseline::query()->firstOrFail();
        $this->assertSame(280, $baseline->evidence['periods'][28]['search']['impressions']);
        $this->assertCount(28, $baseline->evidence['periods'][28]['search']['rows']);
        $this->assertSame('2026-08-16', $baseline->evidence['periods'][28]['search']['from']);
        PageMetric::query()->update(['impressions' => 900]);
        Event::dispatch(new PageProposalAccepted($this->project->id, $proposal->id, $revision->id));
        $this->assertSame(1, PageChangeBaseline::query()->count());
        $this->assertSame(280, $baseline->refresh()->evidence['periods'][28]['search']['impressions']);
        $this->expectException(LogicException::class);
        $baseline->update(['evidence' => []]);
    }

    #[Test]
    public function baseline_is_rolled_back_with_approval_and_rejected_revision_cannot_pin(): void
    {
        [, $proposal, $revision] = $this->proposal();
        DB::beginTransaction();
        Event::dispatch(new PageProposalAccepted($this->project->id, $proposal->id, $revision->id));
        $this->assertSame(1, PageChangeBaseline::query()->count());
        DB::rollBack();
        $this->assertSame(0, PageChangeBaseline::query()->count());
        $proposal->update(['approved_revision_id' => null]);
        $this->expectException(LogicException::class);
        app(PinChangeBaseline::class)->capture($this->project->id, $proposal->id, $revision->id);
    }

    #[Test]
    public function verification_creates_two_idempotent_windows_after_the_pacific_verification_day(): void
    {
        [, $proposal, $revision] = $this->proposal();
        $this->pin($proposal, $revision);
        // Still September 15 in Pacific time; neither partial publication day nor fresh reporting days count.
        $this->travelTo(CarbonImmutable::parse('2026-09-16 01:00:00', 'UTC'));
        $publication = $this->publish($proposal, $revision);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        $this->assertSame(2, PageChangeFollowup::query()->count());
        $fourteen = $this->followup(14);
        $this->assertSame('2026-09-16', $fourteen->window_from->toDateString());
        $this->assertSame('2026-09-29', $fourteen->window_to->toDateString());
        $this->assertSame('2026-10-02', $fourteen->due_on->toDateString());
        $this->assertSame('2026-10-13', $this->followup(28)->window_to->toDateString());
        $this->travelTo(CarbonImmutable::parse('2026-10-02 06:59:00', 'UTC'));
        $this->assertNull(app(CaptureChangeReviews::class)->capture($fourteen));
        $this->assertSame(13, app(ChangeFollowupReport::class)->get()['items'][0]['periods'][0]['settled_days']);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 07:00:00', 'UTC'));
        $review = app(CaptureChangeReviews::class)->capture($fourteen);
        $this->assertNotNull($review);
        $this->assertSame('unavailable', $review->status);
        $this->assertNull($review->evidence['search']['clicks']);
        $this->assertSame('observing', app(ChangeFollowupReport::class)->get()['items'][0]['periods'][1]['status']);
    }

    #[Test]
    public function unverified_publication_cannot_start_and_missing_approval_baseline_remains_unknown(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $publication = $this->publish($proposal, $revision, false);
        try {
            app(StartChangeFollowups::class)->start($this->project->id, $publication->id);
            $this->fail('Unverified publication was accepted.');
        } catch (LogicException) {
            $this->assertSame(0, PageChangeFollowup::query()->count());
        }
        $this->observations([$page], ObservationWindow::baseline(CarbonImmutable::now(), 28), 30);
        $publication->update(['verified_at' => now(), 'verification_snapshot_id' => $this->snapshot($page, 'changed')->id, 'status' => 'verified']);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        $baseline = PageChangeBaseline::query()->firstOrFail();
        $this->assertSame('missing_at_approval', $baseline->evidence['pin_stage']);
        $this->assertNull($baseline->evidence['periods'][28]['search']['impressions']);
        $this->assertSame(2, PageChangeFollowup::query()->count());
    }

    #[Test]
    public function recovery_of_the_original_publication_invalidates_comparison_without_rewriting_old_observations(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $this->observations([$page], ObservationWindow::baseline(CarbonImmutable::now(), 28), 10);
        $this->pin($proposal, $revision);
        $publication = $this->publish($proposal, $revision);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        $this->travelTo(CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'));
        $this->observations([$page], ObservationWindow::afterPublication(CarbonImmutable::instance($publication->verified_at), 14), 20);
        $original = $this->review(14);
        $this->assertTrue($original->evidence['search_comparison_available']);
        $publication->update(['recovered_at' => CarbonImmutable::parse('2026-09-21 12:00:00', 'UTC'), 'status' => 'recovered']);
        $visible = app(ChangeFollowupReport::class)->get()['items'][0]['periods'][0];
        $this->assertSame($publication->id, $visible['current_recoveries'][0]['publication_id']);
        $restated = $this->review(14);
        $this->assertNotSame($original->id, $restated->id);
        $this->assertFalse($restated->evidence['search_comparison_available']);
        $this->assertFalse($restated->evidence['purchase_comparison_available']);
        $this->assertSame('recovered', $restated->evidence['recoveries'][0]['status']);
        $this->assertTrue($original->refresh()->evidence['search_comparison_available']);
        $this->assertSame(280, $restated->evidence['search']['impressions']);
    }

    #[Test]
    public function unknown_recovery_transport_blocks_comparison_until_reconciled_without_fabricating_a_change_time(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $this->observations([$page], ObservationWindow::baseline(CarbonImmutable::now(), 28), 10);
        $this->pin($proposal, $revision);
        $publication = $this->publish($proposal, $revision);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        $channel = Channel::factory()->create();
        $operation = PagePublicationOperation::query()->create(['publication_id' => $publication->id, 'site_page_id' => $page->id,
            'channel_id' => $channel->id, 'kind' => 'recovery', 'status' => 'outcome_unknown', 'delivery_id' => (string) Str::uuid(),
            'request_body' => '{}', 'request_hash' => hash('sha256', '{}'), 'destination' => [], 'authorized_at' => now(),
            'dispatch_started_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC')]);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'));
        $this->observations([$page], ObservationWindow::afterPublication(CarbonImmutable::instance($publication->verified_at), 14), 20);
        $review = $this->review(14);
        $this->assertFalse($review->evidence['search_comparison_available']);
        $this->assertSame('outcome_unknown', $review->evidence['recoveries'][0]['status']);
        $this->assertNull($operation->fresh()->committed_at);
        $operation->update(['status' => 'conflict']);
        $this->assertTrue($this->review(14)->evidence['search_comparison_available']);
    }

    #[Test]
    public function settled_reviews_retain_partial_read_status_and_are_append_only_and_idempotent(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $this->observations([$page], ObservationWindow::baseline(CarbonImmutable::now(), 28), 10);
        $this->pin($proposal, $revision);
        $publication = $this->publish($proposal, $revision);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        $this->travelTo(CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'));
        $window = ObservationWindow::afterPublication(CarbonImmutable::instance($publication->verified_at), 14);
        $this->observations([$page], $window, 20);
        $review = $this->review(14);
        $this->assertSame('observed', $review->status);
        $this->assertTrue($review->evidence['search_comparison_available']);
        $this->assertSame(280, $review->evidence['search']['impressions']);
        $again = $this->review(14);
        $this->assertSame($review->id, $again->id);
        $this->observations([$page], $window, 0, ReadStatus::Partial);
        $partial = $this->review(14);
        $this->assertSame('partial', $partial->status);
        $this->assertSame(280, $partial->evidence['search']['impressions']);
        $this->assertFalse($partial->evidence['search_comparison_available']);
        $this->assertSame(2, PageChangeReview::query()->count());
        $this->assertSame('observed', $review->refresh()->status);
        $report = app(PagePerformance::class)->forProject($this->project);
        $this->assertSame('partial', $report['changes']['items'][0]['periods'][0]['status']);
        $this->assertSame(140, $report['changes']['items'][0]['periods'][0]['baseline']['search']['impressions']);
    }

    #[Test]
    public function comparison_pages_need_matching_public_evidence_and_wider_context_keeps_the_original_cohort(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $other = $this->page('other');
        $this->snapshot($other, 'stable');
        $this->observations([$page, $other], ObservationWindow::baseline(CarbonImmutable::now(), 28), 10);
        $baseline = $this->pin($proposal, $revision);
        $this->assertCount(1, $baseline->evidence['comparison_candidates']);
        $publication = $this->publish($proposal, $revision);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        $this->travelTo(CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'));
        $window = ObservationWindow::afterPublication(CarbonImmutable::instance($publication->verified_at), 14);
        $later = $this->page('added-later');
        $this->observations([$page, $other, $later], $window, 20);
        $unproven = $this->review(14);
        $this->assertSame('unavailable', $unproven->evidence['comparisons'][0]['status']);
        $this->snapshot($other, 'stable');
        $proven = $this->review(14);
        $this->assertSame('observed_unchanged', $proven->evidence['comparisons'][0]['status']);
        $this->assertTrue($proven->evidence['comparisons'][0]['comparison_available']);
        $this->assertSame(280, $proven->evidence['wider_search']['impressions']);
        $this->assertSame(14, $proven->evidence['wider_search']['expected_page_days']);
        $other->update(['locale' => 'pt']);
        $changed = $this->review(14);
        $this->assertFalse($changed->evidence['comparisons'][0]['comparison_available']);
        $this->assertStringContainsString('identity', $changed->evidence['comparisons'][0]['reason']);
    }

    #[Test]
    public function intervening_changes_disable_a_target_comparison_even_with_complete_readings(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $this->observations([$page], ObservationWindow::baseline(CarbonImmutable::now(), 28), 10);
        $this->pin($proposal, $revision);
        $publication = $this->publish($proposal, $revision);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        $this->travelTo(CarbonImmutable::parse('2026-09-20 18:00:00', 'UTC'));
        $this->snapshot($page, 'unrelated-edit');
        $this->travelTo(CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'));
        $this->observations([$page], ObservationWindow::afterPublication(CarbonImmutable::instance($publication->verified_at), 14), 20);
        $review = $this->review(14);
        $this->assertTrue($review->evidence['later_public_content_changed']);
        $this->assertFalse($review->evidence['search_comparison_available']);
    }

    #[Test]
    public function purchases_keep_frozen_and_reconciled_baselines_separate_and_never_switch_sources(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $source = PurchaseSource::query()->create(['name' => 'Paid ledger', 'kind' => 'webhook', 'is_primary' => true, 'is_enabled' => true, 'tracking_started_at' => '2026-01-01', 'first_received_at' => '2026-01-01', 'verified_at' => now()]);
        $sale = $this->sale($source, $page, '2026-09-01', 'EUR', 5000);
        $baseline = $this->pin($proposal, $revision);
        $publication = $this->publish($proposal, $revision);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        $sale->update(['refunded_minor' => 5000, 'status' => 'refunded']);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'));
        $this->sale($source, $page, '2026-09-20', 'EUR', 7000);
        $this->sale($source, $page, '2026-09-21', 'USD', 8000);
        $review = $this->review(14);
        $this->assertSame(0, $baseline->refresh()->evidence['periods'][14]['purchases']['currencies'][0]['refunded_minor']);
        $this->assertSame(5000, $review->evidence['baseline_reconciled_purchases']['currencies'][0]['refunded_minor']);
        $this->assertCount(2, $review->evidence['purchases']['currencies']);
        $this->assertTrue($review->evidence['purchase_comparison_available']);
        $source->update(['is_primary' => false]);
        PurchaseSource::query()->create(['name' => 'New ledger', 'is_primary' => true]);
        $changed = $this->review(14);
        $this->assertSame($source->id, $changed->evidence['purchases']['source_id']);
        $this->assertFalse($changed->evidence['purchase_comparison_available']);
        $this->assertSame(2, PageChangeReview::query()->count());
    }

    #[Test]
    public function baseline_and_verification_events_cannot_cross_tenants_and_restore_context(): void
    {
        [, $proposal, $revision] = $this->proposal();
        $other = Project::factory()->create();
        app(CurrentProject::class)->set($other);
        $baseline = app(PinChangeBaseline::class)->capture($this->project->id, $proposal->id, $revision->id);
        $this->assertSame($this->project->id, $baseline->project_id);
        $this->assertSame($other->id, app(CurrentProject::class)->id());
        $this->assertSame(0, PageChangeBaseline::query()->count());
        $this->expectException(ModelNotFoundException::class);
        app(PinChangeBaseline::class)->capture($other->id, $proposal->id, $revision->id);
    }

    #[Test]
    public function measurement_sync_recovers_a_missed_verification_event_and_captures_both_settled_windows(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $this->pin($proposal, $revision);
        $publication = $this->publish($proposal, $revision);
        $this->assertSame(0, PageChangeFollowup::query()->count());
        $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
        $gateway = app(SearchConsoleGateway::class);
        $this->assertInstanceOf(FakeSearchConsole::class, $gateway);
        $gateway->willReadPages(new ReadResult(ReadStatus::Complete, [
            new SearchRow($page->url, '2026-09-20', 30, 2, 8.0),
            new SearchRow($page->url, '2026-10-05', 40, 3, 7.0),
        ]));
        app(SynchronizePageMeasurements::class)->sync($this->project);
        $this->assertSame(2, PageChangeReview::query()->count());
        $report = app(PagePerformance::class)->forProject($this->project)['changes']['items'][0];
        $this->assertSame('sparse', $report['periods'][1]['status']);
        $this->assertSame(28, $report['periods'][1]['settled_days']);
        $this->assertSame('2026-10-13', $report['periods'][1]['to']);
        $this->assertSame(70, $report['periods'][1]['review']['search']['impressions']);
        $this->assertSame(30, $report['periods'][0]['review']['search']['impressions']);
        $this->assertNull($report['periods'][1]['baseline']['search']['impressions']);
        $this->assertFalse($report['periods'][1]['review']['search_comparison_available']);
    }

    #[Test]
    public function a_changed_search_property_is_visible_and_disables_comparison(): void
    {
        [$page, $proposal, $revision] = $this->proposal();
        $this->observations([$page], ObservationWindow::baseline(CarbonImmutable::now(), 28), 10);
        $this->pin($proposal, $revision);
        $publication = $this->publish($proposal, $revision);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        $this->travelTo(CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'));
        $this->observations([$page], ObservationWindow::afterPublication(CarbonImmutable::instance($publication->verified_at), 14), 20, ReadStatus::Complete, 'https://example.com/');
        $review = $this->review(14);
        $this->assertSame('https://example.com/', $review->evidence['search']['property']);
        $this->assertFalse($review->evidence['search_comparison_available']);
        $this->assertStringContainsString('Search Console property', implode(' ', $review->evidence['limitations']));
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function pacificBoundaries(): array
    {
        return [
            'daylight saving' => ['2026-09-15 18:00:00', '2026-09-30 06:59:59', '2026-09-30 07:00:00', '2026-10-02 18:00:00'],
            'standard time after DST transition' => ['2026-10-20 18:00:00', '2026-11-04 07:59:59', '2026-11-04 08:00:00', '2026-11-06 18:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('pacificBoundaries')]
    public function snapshot_and_publication_boundaries_use_pacific_days_as_utc_instants(string $approvalAt, string $inside, string $outside, string $reviewAt): void
    {
        $this->travelTo(CarbonImmutable::parse($approvalAt, 'UTC'));
        [$page, $proposal, $revision] = $this->proposal();
        $other = $this->page('comparison');
        $this->snapshot($other, 'stable');
        $this->observations([$page, $other], ObservationWindow::baseline(CarbonImmutable::now(), 28), 10);
        $this->pin($proposal, $revision);
        $publication = $this->publish($proposal, $revision);
        Event::dispatch(new PageChangeVerified($this->project->id, $publication->id));
        // This timestamp is on the next UTC date but the observation day has not finished in Pacific time.
        $this->travelTo(CarbonImmutable::parse($inside, 'UTC'));
        $this->snapshot($other, 'stable');
        $this->travelTo(CarbonImmutable::parse($outside, 'UTC'));
        $this->snapshot($page, 'edit-after-window');
        $this->travelTo(CarbonImmutable::parse($reviewAt, 'UTC'));
        $this->observations([$page, $other], ObservationWindow::afterPublication(CarbonImmutable::instance($publication->verified_at), 14), 20);
        $first = $this->review(14);
        $this->assertFalse($first->evidence['later_public_content_changed']);
        $this->assertSame('unavailable', $first->evidence['comparisons'][0]['status']);
        // A snapshot exactly at next Pacific midnight can establish a completed-window comparison.
        $this->travelTo(CarbonImmutable::parse($outside, 'UTC'));
        $this->snapshot($other, 'stable');
        $this->travelTo(CarbonImmutable::parse($reviewAt, 'UTC'));
        $this->assertTrue($this->review(14)->evidence['comparisons'][0]['comparison_available']);
        $this->travelTo(CarbonImmutable::parse($inside, 'UTC'));
        $this->snapshot($page, 'edit-inside-window');
        $extraRevision = PageProposalRevision::query()->create([
            'proposal_id' => $proposal->id, 'site_page_id' => $page->id, 'source_snapshot_id' => $revision->source_snapshot_id,
            'number' => 2, 'canonical_url' => $page->url, 'locale' => 'en', 'changes' => [], 'patch_hash' => hash('sha256', 'another'),
            'evidence_snapshot' => [], 'measurement_plan' => [], 'missing_facts' => [],
        ]);
        $extra = $this->publish($proposal, $extraRevision, false);
        $extra->update(['applied_at' => now()]);
        $this->travelTo(CarbonImmutable::parse($reviewAt, 'UTC'));
        $last = $this->review(14);
        $this->assertTrue($last->evidence['later_public_content_changed']);
        $this->assertSame([$extra->id], $last->evidence['intervening_publications']);
        $this->assertFalse($last->evidence['search_comparison_available']);
    }

    private function page(string $slug): SitePage
    {
        $url = 'https://example.com/'.$slug;

        return SitePage::factory()->create(['url' => $url, 'canonical_url' => $url, 'canonical_hash' => hash('sha256', $url), 'title' => $slug, 'locale' => 'en', 'page_kind' => SitePageKind::Commercial, 'tracked_at' => now()]);
    }

    /** @return array{SitePage, PageProposal, PageProposalRevision} */
    private function proposal(): array
    {
        $page = $this->page('service');
        $snapshot = $this->snapshot($page, 'original');
        $opportunity = PageOpportunity::query()->create(['site_page_id' => $page->id, 'kind' => 'search_intent', 'diagnosed_issue' => 'Observed demand', 'suggested_scope' => 'Clarify title', 'evidence_snapshot' => [], 'confidence' => 'medium', 'effort' => 'small', 'ranking_factors' => [], 'missing_fact_questions' => [], 'overlap_page_ids' => [], 'fingerprint' => hash('sha256', $page->id), 'diagnosed_at' => now()]);
        $proposal = PageProposal::query()->create(['opportunity_id' => $opportunity->id, 'site_page_id' => $page->id, 'status' => 'approved']);
        $revision = PageProposalRevision::query()->create(['proposal_id' => $proposal->id, 'site_page_id' => $page->id, 'source_snapshot_id' => $snapshot->id, 'number' => 1, 'canonical_url' => $page->url, 'locale' => 'en', 'changes' => [], 'patch_hash' => hash('sha256', 'patch'), 'evidence_snapshot' => [], 'measurement_plan' => ['known_confounders' => ['Seasonal campaign']], 'missing_facts' => []]);
        $proposal->update(['current_revision_id' => $revision->id, 'approved_revision_id' => $revision->id]);

        return [$page, $proposal, $revision];
    }

    private function snapshot(SitePage $page, string $content): PageSnapshot
    {
        return PageSnapshot::query()->create(['site_page_id' => $page->id, 'source_kind' => 'public', 'source_url' => $page->url, 'captured_at' => now(), 'revision' => (string) Str::uuid(), 'content_hash' => hash('sha256', $content), 'fields' => ['title' => $content], 'editable_fields' => [], 'metadata' => ['canonical_url' => $page->canonical_url, 'locale' => $page->locale]]);
    }

    private function pin(PageProposal $proposal, PageProposalRevision $revision): PageChangeBaseline
    {
        return app(PinChangeBaseline::class)->capture($this->project->id, $proposal->id, $revision->id);
    }

    private function publish(PageProposal $proposal, PageProposalRevision $revision, bool $verified = true): PagePublication
    {
        $page = SitePage::query()->whereKey($proposal->site_page_id)->firstOrFail();

        return PagePublication::query()->create(['proposal_id' => $proposal->id, 'revision_id' => $revision->id, 'site_page_id' => $page->id, 'delivery_id' => (string) Str::uuid(), 'status' => $verified ? 'verified' : 'awaiting_operator', 'authorized_at' => now(), 'applied_at' => $verified ? now() : null, 'verified_at' => $verified ? now() : null, 'verification_snapshot_id' => $verified ? $this->snapshot($page, 'changed')->id : null]);
    }

    private function review(int $days): PageChangeReview
    {
        $review = app(CaptureChangeReviews::class)->capture($this->followup($days));
        $this->assertNotNull($review);

        return $review;
    }

    private function followup(int $days): PageChangeFollowup
    {
        return PageChangeFollowup::query()->where('days', $days)->firstOrFail();
    }

    /** @param list<SitePage> $pages */
    private function observations(array $pages, ObservationWindow $window, int $impressions, ReadStatus $status = ReadStatus::Complete, string $property = 'sc-domain:example.com'): void
    {
        $read = MeasurementRead::query()->create(['source' => 'gsc_pages', 'window_from' => $window->from->toDateString(), 'window_to' => $window->to->toDateString(), 'status' => $status, 'started_at' => now(), 'finished_at' => now(), 'metadata' => ['property' => $property, 'page_ids' => array_map(static fn (SitePage $page): string => $page->id, $pages)]]);
        if ($status !== ReadStatus::Complete) {
            return;
        }
        foreach ($pages as $page) {
            for ($day = $window->from; $day->lessThanOrEqualTo($window->to); $day = $day->addDay()) {
                PageMetric::query()->updateOrCreate(['site_page_id' => $page->id, 'measured_on' => $day->toDateString()], ['measurement_read_id' => $read->id, 'impressions' => $impressions, 'clicks' => 1, 'position_tenths' => 100]);
            }
        }
    }

    private function sale(PurchaseSource $source, SitePage $page, string $day, string $currency, int $amount): PurchaseRecord
    {
        return PurchaseRecord::query()->create(['purchase_source_id' => $source->id, 'site_page_id' => $page->id, 'transaction_id' => (string) Str::uuid(), 'revision' => 1, 'status' => 'paid', 'amount_minor' => $amount, 'refunded_minor' => 0, 'currency' => $currency, 'purchased_at' => $day.' 18:00:00', 'occurred_at' => $day.' 18:00:00', 'landing_url' => $page->url, 'attribution_status' => 'attributed', 'payload_hash' => hash('sha256', (string) Str::uuid())]);
    }
}
