<?php

declare(strict_types=1);

namespace Tests\Feature\Opportunities;

use App\Feedback\Followups\CaptureChangeReviews;
use App\Feedback\Followups\ObservationWindow;
use App\Feedback\Followups\PinChangeBaseline;
use App\Feedback\Followups\StartChangeFollowups;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\MeasurementRead;
use App\Models\PageChangeFollowup;
use App\Models\PageMetric;
use App\Models\PageOpportunity;
use App\Models\PageOutcomeReview;
use App\Models\PageProposal;
use App\Models\PageProposalRevision;
use App\Models\PagePublication;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Opportunities\DiagnoseOpportunities;
use App\Opportunities\PageOutcomeReviews;
use App\Pages\TrackedPages;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class PageOutcomeReviewsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private SitePage $page;

    private PagePublication $publication;

    private PageProposal $proposal;

    private string $title = 'Home cleaning service';

    private string $body = 'Our home cleaning service includes floors and bathrooms. Customers can book a regular visit or request help preparing for a special occasion.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 18:00:00', 'UTC'));
        $this->project = Project::factory()->create(['website_url' => 'https://example.com']);
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $this->actingAs($this->owner);
        Http::fake(fn () => Http::response('<html lang="en"><head><title>'.$this->title.'</title><link rel="canonical" href="https://example.com/service"></head><body><main><h1>'.$this->title.'</h1><p>'.$this->body.'</p></main></body></html>', 200, ['Content-Type' => 'text/html']));
        $this->page = app(TrackedPages::class)->track($this->project, 'https://example.com/service', 'en', 'commercial');
        $this->observations(ObservationWindow::baseline(CarbonImmutable::now(), 28));
        $opportunity = PageOpportunity::query()->create(['site_page_id' => $this->page->id, 'kind' => 'missing_business_fact', 'status' => 'proposed', 'diagnosed_issue' => 'Clarify a title', 'suggested_scope' => 'One title', 'evidence_snapshot' => [], 'confidence' => 'low', 'effort' => 'small', 'ranking_factors' => [], 'missing_fact_questions' => [], 'overlap_page_ids' => [], 'fingerprint' => hash('sha256', 'first'), 'diagnosed_at' => now()]);
        $this->proposal = PageProposal::query()->create(['opportunity_id' => $opportunity->id, 'site_page_id' => $this->page->id, 'status' => 'approved']);
        $revision = PageProposalRevision::query()->create(['proposal_id' => $this->proposal->id, 'site_page_id' => $this->page->id, 'source_snapshot_id' => $this->page->latestSnapshot->id, 'number' => 1, 'canonical_url' => $this->page->canonical_url, 'locale' => 'en', 'changes' => [], 'patch_hash' => hash('sha256', 'verified-fixture'), 'evidence_snapshot' => [], 'measurement_plan' => [], 'missing_facts' => []]);
        $this->proposal->update(['current_revision_id' => $revision->id, 'approved_revision_id' => $revision->id]);
        app(PinChangeBaseline::class)->capture($this->project->id, $this->proposal->id, $revision->id);
        $this->title .= ' reviewed';
        $this->page = app(TrackedPages::class)->capture($this->project, $this->page);
        $this->publication = PagePublication::query()->create(['proposal_id' => $this->proposal->id, 'revision_id' => $revision->id, 'site_page_id' => $this->page->id, 'delivery_id' => (string) Str::uuid(), 'mode' => 'assisted', 'status' => 'verified', 'authorized_at' => now(), 'applied_at' => now(), 'verified_at' => now(), 'verification_snapshot_id' => $this->page->latestSnapshot->id]);
        app(StartChangeFollowups::class)->start($this->project->id, $this->publication->id);
    }

    public function test_a_decision_can_leave_the_page_unchanged_without_generating_work_and_is_immutable(): void
    {
        $review = $this->record('leave_unchanged');
        $this->assertNull($review->next_opportunity_id);
        app(DiagnoseOpportunities::class)->refresh($this->project);
        $this->assertSame(1, PageOpportunity::query()->count());
        $this->assertSame('proposed', $this->proposal->opportunity->fresh()->status);
        $this->expectException(LogicException::class);
        $review->update(['reason' => 'Rewrite history']);
    }

    public function test_reassessment_requires_settled_observation_and_never_starts_ai_or_publication(): void
    {
        try {
            $this->record('reassess');
            $this->fail('An unobserved cycle must wait.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('settled', $exception->getMessage());
        }
        $this->settle();
        $old = $this->proposal->opportunity->toArray();
        $review = $this->record('reassess');
        $this->assertNotNull($review->next_opportunity_id);
        $next = PageOpportunity::query()->findOrFail($review->next_opportunity_id);
        $this->assertSame('open', $next->status);
        $this->assertSame('outcome_reassessment', $next->evidence_snapshot['diagnosis_mode']);
        $this->assertSame($review->id, $next->evidence_snapshot['outcome_review']['id']);
        $this->assertSame($old, $this->proposal->opportunity->fresh()->toArray());
        $this->assertSame(1, PageProposal::query()->count());
        $this->assertSame(1, PagePublication::query()->count());
        $this->assertDatabaseCount('pipeline_runs', 0);
        app(DiagnoseOpportunities::class)->refresh($this->project);
        $this->assertSame('open', $next->fresh()->status);
    }

    public function test_no_justified_new_change_is_a_recorded_successful_review_outcome(): void
    {
        $this->body .= ' Request a quote for the full cleaning price.';
        $this->page = app(TrackedPages::class)->capture($this->project, $this->page);
        $this->settle();
        $review = $this->record('reassess');
        $this->assertNull($review->next_opportunity_id);
        $this->assertStringContainsString('No justified next change', $review->evidence['result']);
        $this->assertSame(1, PageOpportunity::query()->count());
    }

    public function test_stale_tabs_and_outstanding_page_work_cannot_create_duplicate_cycles(): void
    {
        $this->settle();
        $input = $this->input('reassess');
        $first = app(PageOutcomeReviews::class)->record($this->publication, $this->owner, $input);
        $this->assertNotNull($first->next_opportunity_id);
        try {
            app(PageOutcomeReviews::class)->record($this->publication, $this->owner, $input);
            $this->fail('The stale decision must be refused.');
        } catch (ValidationException) {
            $this->assertSame(1, PageOutcomeReview::query()->count());
        }
        $this->post('/publications/'.$this->publication->id.'/outcome-review', $this->input('reassess'))->assertSessionHasErrors('outcome');
        $this->assertSame(2, PageOpportunity::query()->count());
    }

    public function test_nonowners_and_other_tenants_cannot_record_an_outcome(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'operator']);
        $this->actingAs($operator)->post('/publications/'.$this->publication->id.'/outcome-review', $this->input('keep_observing'))->assertForbidden();
        $other = Project::factory()->create();
        $otherOwner = User::factory()->create();
        $otherOwner->projects()->attach($other, ['role' => 'owner']);
        app(CurrentProject::class)->set($other);
        $this->actingAs($otherOwner)->post('/publications/'.$this->publication->id.'/outcome-review', ['decision' => 'keep_observing', 'reason' => 'Foreign attempt', 'expected_outcome_id' => null, 'measurement_review_id' => null])->assertNotFound();
    }

    public function test_a_new_unpublished_revision_blocks_another_cycle_even_with_an_older_verified_publication(): void
    {
        $this->settle();
        $old = PageProposalRevision::query()->findOrFail($this->publication->revision_id);
        $next = $old->replicate();
        $next->number = 2;
        $next->save();
        $this->proposal->update(['current_revision_id' => $next->id, 'approved_revision_id' => null, 'status' => 'review_required']);
        $this->post('/publications/'.$this->publication->id.'/outcome-review', $this->input('reassess'))->assertSessionHasErrors('outcome');
        $this->assertDatabaseCount('page_outcome_reviews', 0);
        $this->getJson('/home', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'home/index', 'X-Inertia-Partial-Data' => 'needs,pageWork',
        ])->assertOk()->assertJsonPath('props.needs.page_reviews', 1)->assertJsonPath('props.pageWork.items.0.status', 'review_required');
    }

    public function test_failed_public_read_keeps_reassessment_reviewable_without_creating_work(): void
    {
        $this->settle();
        Http::fake(fn () => throw new ConnectionException('Synthetic connection failure'));
        $this->post('/publications/'.$this->publication->id.'/outcome-review', $this->input('reassess'))->assertSessionHasErrors('outcome');
        $this->assertDatabaseCount('page_outcome_reviews', 0);
        $this->assertDatabaseCount('page_opportunities', 1);
    }

    private function settle(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 18:00:00', 'UTC'));
        $followup = PageChangeFollowup::query()->where('days', 14)->firstOrFail();
        $this->observations(new ObservationWindow(CarbonImmutable::parse($followup->window_from->toDateString(), ObservationWindow::ZONE), CarbonImmutable::parse($followup->window_to->toDateString(), ObservationWindow::ZONE), 14));
        app(CaptureChangeReviews::class)->capture($followup);
    }

    /** @return array<string,mixed> */
    private function input(string $decision): array
    {
        $context = app(PageOutcomeReviews::class)->context($this->publication);

        return ['decision' => $decision, 'reason' => 'Review the current measured outcome and buyer information.', 'expected_outcome_id' => $context['latest_id'], 'measurement_review_id' => $context['measurement_review_id']];
    }

    private function record(string $decision): PageOutcomeReview
    {
        return app(PageOutcomeReviews::class)->record($this->publication, $this->owner, $this->input($decision));
    }

    private function observations(ObservationWindow $window): void
    {
        $read = MeasurementRead::query()->create(['source' => 'gsc_pages', 'window_from' => $window->from->toDateString(), 'window_to' => $window->to->toDateString(), 'status' => 'complete', 'started_at' => now(), 'finished_at' => now(), 'metadata' => ['property' => 'sc-domain:example.com', 'page_ids' => [$this->page->id]]]);
        for ($day = $window->from; $day->lessThanOrEqualTo($window->to); $day = $day->addDay()) {
            PageMetric::query()->updateOrCreate(['site_page_id' => $this->page->id, 'measured_on' => $day->toDateString()], ['measurement_read_id' => $read->id, 'impressions' => 30, 'clicks' => 1, 'position_tenths' => 100]);
        }
    }
}
