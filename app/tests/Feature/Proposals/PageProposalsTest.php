<?php

declare(strict_types=1);

namespace Tests\Feature\Proposals;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Billing\Entitlements;
use App\Enums\PipelineRunStatus;
use App\Models\BusinessFact;
use App\Models\ContentItem;
use App\Models\PageOpportunity;
use App\Models\PageProposal;
use App\Models\PagePublication;
use App\Models\PageSnapshot;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\SitePage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Pages\BusinessFacts;
use App\Pages\TrackedPages;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\Proposals\WritePageProposal;
use App\Proposals\PageBlocks;
use App\Proposals\ProposalPatch;
use App\Proposals\Proposals;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PageProposalsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private SitePage $page;

    private BusinessFact $fact;

    private PageOpportunity $opportunity;

    private FakeModelGateway $models;

    private string $publicHtml;

    private int $publicStatus = 200;

    private bool $pauseOnRead = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T12:30:00Z'));
        $this->project = Project::factory()->create(['website_url' => 'https://example.com']);
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $this->actingAs($this->owner);
        config(['queue.default' => 'sync']);
        $this->models = new FakeModelGateway;
        $this->app->instance(ModelGateway::class, $this->models);
        $this->publicHtml = $this->html();
        Http::fake(function () {
            if ($this->pauseOnRead) {
                SitePage::query()->whereKey($this->page->id)->update(['tracked_at' => null]);
            }

            return Http::response($this->publicHtml, $this->publicStatus);
        });
        $this->page = app(TrackedPages::class)->track($this->project, 'https://example.com/en/service', 'en', 'commercial');
        $this->fact = app(BusinessFacts::class)->save($this->project, $this->owner, $this->factData());
        $this->opportunity = PageOpportunity::query()->create([
            'site_page_id' => $this->page->id, 'kind' => 'missing_business_fact', 'diagnosed_issue' => 'Clarify the included cleaning scope.',
            'suggested_scope' => 'One service scope paragraph.', 'evidence_snapshot' => ['search' => ['clicks' => null], 'snapshot_id' => $this->page->latestSnapshot->id, 'canonical_url' => $this->page->canonical_url, 'locale' => 'en'],
            'confidence' => 'low', 'effort' => 'small', 'ranking_factors' => ['business_relevance' => 'Service page'], 'missing_fact_questions' => ['What does cleaning include?'],
            'overlap_page_ids' => [], 'status' => 'open', 'fingerprint' => hash('sha256', 'scope'), 'diagnosed_at' => now(),
        ]);
    }

    public function test_generation_pins_real_source_and_confirmed_evidence_without_creating_an_article_or_delivery(): void
    {
        $proposal = $this->generate();
        $revision = $proposal->currentRevision;
        $this->assertSame('review_required', $proposal->status);
        $this->assertSame($this->page->latestSnapshot->id, $revision->source_snapshot_id);
        $this->assertSame($this->fact->current_version_id, $revision->facts()->sole()->fact_version_id);
        $this->assertSame(PipelineRunStatus::Completed, PipelineRun::query()->sole()->status);
        $this->assertSame(0, ContentItem::query()->count());
        $this->assertSame(0, WebhookDelivery::query()->count());
        $this->assertSame(0, PagePublication::query()->count());
        $this->assertStringContainsString('not proof', $this->models->lastRequest()->instructions);
        $this->get('/proposals/'.$proposal->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('proposals/show')->where('proposal.current_revision_id', $revision->id)->has('proposal.revisions.0.facts', 1));
        $this->post('/opportunities/'.$this->opportunity->id.'/proposals')->assertRedirect();
        $this->assertCount(1, $this->models->sent(), 'Repeated opening cannot spend on another generation.');
    }

    public function test_withdrawn_dismissed_and_stale_opportunities_cannot_start_generation_even_from_an_old_bound_model(): void
    {
        foreach (['dismissed', 'withdrawn'] as $status) {
            PageOpportunity::query()->whereKey($this->opportunity->id)->update(['status' => $status]);
            try {
                app(Proposals::class)->begin($this->opportunity, $this->owner);
                $this->fail('The stale route-bound opportunity must be reloaded.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
        $this->opportunity->update(['status' => 'open']);
        $this->publicHtml = $this->html('A different current scope.');
        app(TrackedPages::class)->track($this->project, $this->page->url, 'en', 'commercial');
        $this->postJson('/opportunities/'.$this->opportunity->id.'/proposals')->assertConflict();
        $this->assertSame(0, PageProposal::query()->count());
        $this->assertSame(0, PipelineRun::query()->count());
        $this->assertSame([], $this->models->sent());
    }

    public function test_explicit_regeneration_reassesses_changed_source_without_mutating_historical_diagnosis(): void
    {
        $proposal = $this->generate();
        $this->accept($proposal);
        $oldRevision = $proposal->currentRevision;
        $oldEvidence = $this->opportunity->fresh()->evidence_snapshot;
        $this->publicHtml = $this->html('Cleaning includes floors and bathrooms.');
        $currentPage = app(TrackedPages::class)->track($this->project, $this->page->url, 'en', 'commercial');
        $this->models->willAnswer([json_encode(['changes' => [], 'missing_facts' => [], 'no_change_reason' => 'The current page already answers the supported scope question.'], JSON_THROW_ON_ERROR)]);
        $this->post('/proposals/'.$proposal->id.'/regenerate', ['revision_id' => $proposal->current_revision_id, 'reason' => 'Reassess the scope after the website operator updated it.'])->assertRedirect()->assertSessionHasNoErrors();
        $proposal->refresh();
        $revision = $proposal->currentRevision;
        $this->assertNull($proposal->approved_revision_id);
        $this->assertSame($currentPage->latestSnapshot->id, $revision->source_snapshot_id);
        $this->assertSame('owner_reassessment', $revision->evidence_snapshot['diagnosis_mode']);
        $this->assertSame($oldEvidence, $revision->evidence_snapshot['original_evidence']);
        $this->assertArrayHasKey('windows', $revision->evidence_snapshot);
        $this->assertArrayHasKey('sources', $revision->evidence_snapshot);
        $this->assertSame($oldEvidence, $this->opportunity->fresh()->evidence_snapshot);
        $this->assertSame($oldRevision->source_snapshot_id, $oldRevision->fresh()->source_snapshot_id);
        $this->assertSame([], $revision->changes);
        $this->assertCount(2, $this->models->sent());
    }

    public function test_verification_refuses_removed_forms_changed_existing_links_and_unapproved_markup(): void
    {
        $protected = '<p>See <a href="/en/booking">booking details</a>.</p><form action="/book" method="post"><input type="hidden" name="_token" value="old"><input name="email" required><button type="submit">Book</button></form>';
        $this->publicHtml = str_replace('</main>', $protected.'</main>', $this->html());
        $this->page = app(TrackedPages::class)->track($this->project, $this->page->url, 'en', 'commercial');
        $this->opportunity->update(['evidence_snapshot' => [...$this->opportunity->evidence_snapshot, 'snapshot_id' => $this->page->latestSnapshot->id]]);
        $proposal = $this->generate();
        $publication = $this->authorize($proposal);
        $this->recordApplied($publication);
        $approved = str_replace('</main>', $protected.'</main>', $this->html('Cleaning includes floors and bathrooms.'));
        foreach ([
            preg_replace('/<form.*?<\/form>/', '', $approved),
            str_replace('href="/en/booking"', 'href="/en/unrelated"', $approved),
            str_replace('<p>Cleaning includes floors and bathrooms.</p>', '<h2>Cleaning includes floors and bathrooms.</h2>', $approved),
        ] as $broken) {
            $this->publicHtml = $broken;
            $this->post('/publications/'.$publication->id.'/verify')->assertRedirect()->assertSessionHasNoErrors();
            $this->assertNull($publication->refresh()->verified_at);
            $this->assertSame('verification_failed', $publication->status);
        }
        $this->publicHtml = str_replace('value="old"', 'value="fresh-token"', $approved);
        $this->post('/publications/'.$publication->id.'/verify')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('verified', $publication->refresh()->status);
        $this->assertSame(4, $publication->checks()->count());
    }

    public function test_no_justified_change_is_a_valid_review_outcome_not_a_forced_rewrite(): void
    {
        $proposal = $this->generate(['changes' => [], 'missing_facts' => [], 'no_change_reason' => 'The observed decline is brand-query demand, with no evidenced text defect.']);
        $this->assertSame([], $proposal->currentRevision->changes);
        $this->assertStringContainsString('no evidenced text defect', $proposal->currentRevision->no_change_reason);
        $this->post('/proposals/'.$proposal->id.'/accept', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 5])->assertSessionHasErrors('approval');
        $this->post('/proposals/'.$proposal->id.'/dismiss', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 12, 'reason' => 'No useful change supported.'])->assertRedirect();
        $this->assertSame('dismissed', $proposal->refresh()->status);
        $this->assertSame(12, $proposal->reviews()->sole()->active_seconds);
    }

    public function test_acceptance_and_export_do_not_publish_and_the_existing_service_and_article_both_verify_in_place(): void
    {
        foreach (['commercial', 'editorial'] as $kind) {
            if ($kind === 'editorial') {
                $this->page->update(['page_kind' => $kind, 'is_article' => true]);
                $this->opportunity = PageOpportunity::query()->create([...$this->opportunity->only(['site_page_id', 'kind', 'diagnosed_issue', 'suggested_scope', 'evidence_snapshot', 'confidence', 'effort', 'ranking_factors', 'missing_fact_questions', 'overlap_page_ids']), 'fingerprint' => hash('sha256', $kind), 'status' => 'open', 'diagnosed_at' => now()]);
                $this->publicHtml = $this->html();
                $this->page = app(TrackedPages::class)->track($this->project, $this->page->url, 'en', $kind);
                $this->opportunity->update(['evidence_snapshot' => [...$this->opportunity->evidence_snapshot, 'snapshot_id' => $this->page->latestSnapshot->id]]);
            }
            $proposal = $this->generate();
            $this->accept($proposal);
            $this->assertSame(0, $proposal->publications()->count());
            $this->post('/proposals/'.$proposal->id.'/publish', ['revision_id' => $proposal->current_revision_id])->assertRedirect();
            $publication = $proposal->publications()->sole();
            $this->get('/publications/'.$publication->id.'/handoff')->assertOk()->assertSee('Downloading this document does not publish anything');
            $this->assertNull($publication->fresh()->applied_at);
            $this->assertNull($publication->fresh()->verified_at);
            $this->post('/publications/'.$publication->id.'/verify')->assertConflict();
            $this->recordApplied($publication);
            $this->publicHtml = $this->html('Cleaning includes floors and bathrooms.');
            $this->post('/publications/'.$publication->id.'/verify')->assertRedirect();
            $publication->refresh();
            $this->assertSame('verified', $publication->status);
            $this->assertNotNull($publication->verified_at);
            $this->assertNotNull($publication->verification_snapshot_id);
            $this->assertSame('https://example.com/en/service', $this->page->fresh()->canonical_url);
            $this->assertSame('Website operator', $publication->applied_by_name);
            $this->post('/publications/'.$publication->id.'/verify')->assertRedirect();
            $this->assertSame(1, $publication->checks()->count());
        }
        $this->assertSame(0, ContentItem::query()->count());
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_dismissal_survives_stale_accept_revision_and_fact_changes(): void
    {
        $proposal = $this->generate();
        $revisionId = $proposal->current_revision_id;
        $this->post('/proposals/'.$proposal->id.'/dismiss', ['revision_id' => $revisionId, 'reason' => 'Keep the page as it is.', 'active_seconds' => 20])->assertRedirect();
        $this->postJson('/proposals/'.$proposal->id.'/accept', ['revision_id' => $revisionId, 'active_seconds' => 10])->assertConflict();
        $this->postJson('/proposals/'.$proposal->id.'/revisions', [...$this->proposalPatch(), 'revision_id' => $revisionId, 'reason' => 'Old tab edit.', 'active_seconds' => 10])->assertConflict();
        app(BusinessFacts::class)->save($this->project, $this->owner, $this->factData(['statement' => 'Cleaning includes floors only.', 'expected_version_id' => $this->fact->current_version_id]), $this->fact);
        $this->assertSame('dismissed', $proposal->refresh()->status);
        $this->assertSame($revisionId, $proposal->current_revision_id);
        $this->assertSame(1, $proposal->revisions()->count());
        $this->assertNull($proposal->approved_revision_id);
    }

    public function test_paused_internal_link_target_cannot_be_accepted_or_authorized(): void
    {
        $target = SitePage::factory()->create(['url' => 'https://example.com/en/about', 'canonical_url' => 'https://example.com/en/about', 'canonical_hash' => hash('sha256', 'https://example.com/en/about'), 'locale' => 'en', 'tracked_at' => now()]);
        $input = $this->proposalPatch();
        $input['changes'][0] = [...$input['changes'][0], 'kind' => 'internal_link', 'after' => $input['changes'][0]['before'], 'anchor_text' => 'cleaning service', 'target_page_id' => $target->id, 'fact_version_ids' => []];
        $proposal = $this->generate($input);
        $target->update(['tracked_at' => null]);
        $this->post('/proposals/'.$proposal->id.'/accept', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 10])->assertSessionHasErrors('approval');
        $target->update(['tracked_at' => now()]);
        $this->accept($proposal);
        $target->update(['canonical_url' => 'https://example.com/en/changed']);
        $this->post('/proposals/'.$proposal->id.'/publish', ['revision_id' => $proposal->current_revision_id])->assertSessionHasErrors('publication');
        $this->assertNull($proposal->refresh()->approved_revision_id);
        $this->assertSame(0, $proposal->publications()->count());
    }

    public function test_publication_reads_cannot_reactivate_a_page_paused_while_http_is_in_flight(): void
    {
        $proposal = $this->generate();
        $this->accept($proposal);
        $before = $this->page->snapshots()->count();
        $this->pauseOnRead = true;
        $this->postJson('/proposals/'.$proposal->id.'/publish', ['revision_id' => $proposal->current_revision_id])->assertConflict();
        $this->assertNull($this->page->fresh()->tracked_at);
        $this->assertSame($before, $this->page->snapshots()->count());
        $this->assertSame(0, $proposal->publications()->count());

        // An explicit tracking action is allowed to resume it; publication reads are not.
        $this->pauseOnRead = false;
        $this->page = app(TrackedPages::class)->track($this->project, $this->page->url, 'en', 'commercial');
        $publication = $this->authorize($proposal);
        $this->recordApplied($publication);
        $before = $this->page->snapshots()->count();
        $this->publicHtml = $this->html('Cleaning includes floors and bathrooms.');
        $this->pauseOnRead = true;
        $this->post('/publications/'.$publication->id.'/verify')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->page->fresh()->tracked_at);
        $this->assertNull($publication->refresh()->verified_at);
        $this->assertSame($before, $this->page->snapshots()->count());
        $this->assertSame('verification_failed', $publication->status);
    }

    public function test_an_invalidated_handoff_needs_separate_explicit_authorization_after_reacceptance(): void
    {
        $proposal = $this->generate();
        $publication = $this->authorize($proposal);
        app(Proposals::class)->invalidate($proposal, 'A source review is required.');
        $this->accept($proposal);
        $this->get('/publications/'.$publication->id.'/handoff')->assertConflict();
        $this->postJson('/publications/'.$publication->id.'/applied', ['revision_id' => $publication->revision_id, 'applied_by_name' => 'Operator', 'applied_at' => now()->toIso8601String(), 'application_note' => 'Old handoff.', 'confirm_applied' => true])->assertConflict();
        $this->assertNull($publication->refresh()->applied_at);
        $this->post('/proposals/'.$proposal->id.'/publish', ['revision_id' => $proposal->current_revision_id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('awaiting_operator', $publication->refresh()->status);
        $this->assertSame(1, $proposal->publications()->count());
        $this->assertSame(2, $proposal->reviews()->where('action', 'authorize_publication')->count());
        $this->get('/publications/'.$publication->id.'/handoff')->assertOk();
        $this->recordApplied($publication);
    }

    public function test_revising_clears_approval_preserves_prior_revision_and_refuses_stale_actions(): void
    {
        $proposal = $this->generate();
        $this->accept($proposal);
        $old = $proposal->current_revision_id;
        $input = $this->proposalPatch();
        $input['changes'][0]['after'] = 'Floors and bathrooms are included in cleaning.';
        $this->post('/proposals/'.$proposal->id.'/revisions', [...$input, 'revision_id' => $old, 'reason' => 'Make the scope clearer.', 'active_seconds' => 45])->assertRedirect();
        $proposal->refresh();
        $this->assertNull($proposal->approved_revision_id);
        $this->assertNotSame($old, $proposal->current_revision_id);
        $this->assertSame(2, $proposal->revisions()->count());
        $this->post('/proposals/'.$proposal->id.'/accept', ['revision_id' => $old, 'active_seconds' => 10])->assertSessionHasErrors('revision');
        $this->post('/proposals/'.$proposal->id.'/publish', ['revision_id' => $old])->assertSessionHasErrors('revision');
        $this->assertSame(45, $proposal->reviews()->where('action', 'revise')->sole()->active_seconds);
    }

    public function test_fact_revision_invalidates_approval_and_an_outstanding_handoff_without_mutating_evidence(): void
    {
        $proposal = $this->generate();
        $publication = $this->authorize($proposal);
        $oldFact = $this->fact->current_version_id;
        app(BusinessFacts::class)->save($this->project, $this->owner, $this->factData(['statement' => 'Cleaning includes floors only.', 'expected_version_id' => $oldFact]), $this->fact);
        $this->assertNull($proposal->refresh()->approved_revision_id);
        $this->assertSame('review_required', $publication->refresh()->status);
        $this->get('/publications/'.$publication->id.'/handoff')->assertConflict();
        $this->assertSame($oldFact, $proposal->currentRevision->facts()->sole()->fact_version_id);
        $this->post('/proposals/'.$proposal->id.'/accept', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 10])->assertSessionHasErrors('approval');
    }

    public function test_expired_evidence_and_changed_public_source_refuse_publication(): void
    {
        $proposal = $this->generate();
        $this->accept($proposal);
        $this->travel(32)->days();
        $this->post('/proposals/'.$proposal->id.'/publish', ['revision_id' => $proposal->current_revision_id])->assertSessionHasErrors('publication');
        $this->assertNull($proposal->refresh()->approved_revision_id);
        $this->assertSame(0, PagePublication::query()->count());
    }

    public function test_an_external_edit_between_approval_and_handoff_requires_a_new_review(): void
    {
        $proposal = $this->generate();
        $this->accept($proposal);
        $this->publicHtml = $this->html('Externally changed service scope.');
        $this->post('/proposals/'.$proposal->id.'/publish', ['revision_id' => $proposal->current_revision_id])->assertSessionHasErrors('publication');
        $this->assertSame(0, PagePublication::query()->count());
        $this->assertNull($proposal->refresh()->approved_revision_id);
        $this->assertSame('Our cleaning service.', $proposal->currentRevision->changes[0]['before']);
    }

    public function test_an_operator_report_does_not_hide_mismatched_or_unavailable_public_content(): void
    {
        $proposal = $this->generate();
        $publication = $this->authorize($proposal);
        $this->recordApplied($publication);
        $this->post('/publications/'.$publication->id.'/verify')->assertRedirect();
        $this->assertSame('verification_failed', $publication->refresh()->status);
        $this->assertNull($publication->verified_at);
        $this->publicStatus = 503;
        $this->post('/publications/'.$publication->id.'/verify')->assertRedirect();
        $this->assertNull($publication->refresh()->verified_at);
        $this->assertSame(2, $publication->checks()->count());
    }

    public function test_unsafe_overbroad_ambiguous_and_foreign_evidence_patches_are_rejected(): void
    {
        $base = $this->proposalPatch();
        $variants = [
            [...$base, 'changes' => [[...$base['changes'][0], 'after' => '<script>alert(1)</script>']]],
            [...$base, 'changes' => array_fill(0, 6, $base['changes'][0])],
            [...$base, 'changes' => [$base['changes'][0], $base['changes'][0]]],
            [...$base, 'changes' => [[...$base['changes'][0], 'before' => 'Not on this page']]],
            [...$base, 'changes' => [[...$base['changes'][0], 'fact_version_ids' => [(string) Str::ulid()]]]],
        ];
        foreach ($variants as $input) {
            try {
                app(ProposalPatch::class)->validate($this->page->latestSnapshot, $input, [$this->fact->current_version_id]);
                $this->fail('Unsafe patch should be rejected.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $proposal = $this->generate($variants[0]);
        $this->assertSame('failed', $proposal->status);
        $this->assertNull($proposal->current_revision_id);
    }

    public function test_missing_confirmation_is_visible_and_cannot_be_bypassed_by_removing_the_question(): void
    {
        $patch = $this->proposalPatch();
        $patch['changes'][0]['fact_version_ids'] = [];
        $proposal = $this->generate($patch);
        $this->assertSame('failed', $proposal->status);
        $this->assertNull($proposal->current_revision_id);
        $this->assertNull($proposal->refresh()->approved_revision_id);
    }

    public function test_manual_revision_cannot_remove_all_supporting_facts(): void
    {
        $proposal = $this->generate();
        $patch = $this->proposalPatch();
        $patch['changes'][0]['fact_version_ids'] = [];
        try {
            app(Proposals::class)->revise($proposal, $this->owner, $proposal->current_revision_id, $patch, 'Remove evidence', 0);
            $this->fail('Text without confirmed supporting facts must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('supporting fact', $exception->getMessage());
            $this->assertSame($proposal->current_revision_id, $proposal->fresh()->current_revision_id);
        }
    }

    public function test_internal_link_verification_requires_the_exact_phrase_and_tracked_same_language_destination(): void
    {
        $target = SitePage::query()->create(['url' => 'https://example.com/en/booking', 'canonical_url' => 'https://example.com/en/booking', 'canonical_hash' => hash('sha256', 'https://example.com/en/booking'), 'locale' => 'en', 'tracked_at' => now(), 'title' => 'Book cleaning', 'is_article' => false]);
        $patch = $this->proposalPatch();
        $patch['changes'][0] = [...$patch['changes'][0], 'kind' => 'internal_link', 'after' => 'Our cleaning service.', 'anchor_text' => 'cleaning service', 'target_page_id' => $target->id, 'fact_version_ids' => []];
        $proposal = $this->generate($patch);
        $publication = $this->authorize($proposal);
        $this->recordApplied($publication);
        $this->publicHtml = $this->html('Our <a href="/en/booking">cleaning service</a>.');
        $this->post('/publications/'.$publication->id.'/verify')->assertRedirect();
        $this->assertSame('verified', $publication->refresh()->status);
    }

    public function test_non_owners_and_other_tenants_cannot_generate_review_publish_or_read_proposals(): void
    {
        $proposal = $this->generate();
        $member = User::factory()->create();
        $member->projects()->attach($this->project, ['role' => 'member']);
        $this->actingAs($member)->postJson('/opportunities/'.$this->opportunity->id.'/proposals')->assertForbidden();
        $this->postJson('/proposals/'.$proposal->id.'/accept', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 1])->assertForbidden();
        $this->postJson('/proposals/'.$proposal->id.'/publish', ['revision_id' => $proposal->current_revision_id])->assertForbidden();
        $other = Project::factory()->create();
        $stranger = User::factory()->create();
        $stranger->projects()->attach($other, ['role' => 'owner']);
        $this->actingAs($stranger)->get('/proposals/'.$proposal->id)->assertNotFound();
        $this->postJson('/proposals/'.$proposal->id.'/accept', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 1])->assertNotFound();
    }

    public function test_revision_history_is_immutable_and_database_source_references_cannot_cross_pages(): void
    {
        $proposal = $this->generate();
        try {
            $proposal->currentRevision->update(['changes' => []]);
            $this->fail('A saved revision is immutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $other = SitePage::query()->create(['url' => 'https://example.com/en/other', 'title' => 'Other', 'is_article' => false]);
        $snapshot = PageSnapshot::query()->create(['site_page_id' => $other->id, 'source_kind' => 'public', 'source_url' => $other->url, 'captured_at' => now(), 'revision' => 'other', 'content_hash' => hash('sha256', 'other'), 'fields' => [], 'editable_fields' => [], 'metadata' => []]);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('page_proposal_revisions')->where('id', $proposal->current_revision_id)->update(['source_snapshot_id' => $snapshot->id]));
    }

    public function test_reviewed_discrepancy_fact_stays_a_dependency_when_the_patch_cites_another_fact(): void
    {
        $originalVersion = $this->fact->current_version_id;
        $other = app(BusinessFacts::class)->save($this->project, $this->owner, $this->factData(['name' => 'Booking', 'statement' => 'Cleaning can be booked online.']));
        $this->opportunity->update(['evidence_snapshot' => [...$this->opportunity->evidence_snapshot, 'diagnosis_mode' => 'reviewed_claim', 'confirmed_facts' => [['version_id' => $originalVersion]]]]);
        $patch = $this->proposalPatch();
        $patch['changes'][0]['after'] = 'Cleaning can be booked online.';
        $patch['changes'][0]['fact_version_ids'] = [$other->current_version_id];
        $proposal = $this->generate($patch);
        $publication = $this->authorize($proposal);
        $this->assertEqualsCanonicalizing([$originalVersion, $other->current_version_id], $proposal->currentRevision->facts()->pluck('fact_version_id')->all());
        $this->models->willAnswer([json_encode($patch, JSON_THROW_ON_ERROR)]);
        $this->post('/proposals/'.$proposal->id.'/regenerate', ['revision_id' => $proposal->current_revision_id, 'reason' => 'Reassess the exact correction.'])->assertRedirect()->assertSessionHasNoErrors();
        $proposal->refresh();
        $this->assertSame('owner_reassessment', $proposal->currentRevision->evidence_snapshot['diagnosis_mode']);
        $this->assertEqualsCanonicalizing([$originalVersion, $other->current_version_id], $proposal->currentRevision->facts()->pluck('fact_version_id')->all());
        $publication = $this->authorize($proposal);
        app(BusinessFacts::class)->save($this->project, $this->owner, $this->factData(['expected_version_id' => $originalVersion, 'statement' => 'Cleaning now includes kitchens only.']), $this->fact);
        $this->assertNull($proposal->refresh()->approved_revision_id);
        $this->assertSame('review_required', $publication->refresh()->status);
        $this->post('/proposals/'.$proposal->id.'/accept', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 10])->assertSessionHasErrors('approval');
        $this->post('/proposals/'.$proposal->id.'/publish', ['revision_id' => $proposal->current_revision_id])->assertConflict();
        $this->post('/proposals/'.$proposal->id.'/revisions', ['revision_id' => $proposal->current_revision_id, 'reason' => 'Keep the unrelated supporting fact only.', 'active_seconds' => 10, ...$patch])->assertSessionHasErrors('changes');
        $this->assertSame(2, $proposal->revisions()->count());
    }

    public function test_generated_patch_cannot_drop_a_reviewed_fact_changed_while_it_was_queued(): void
    {
        Queue::fake();
        $originalVersion = $this->fact->current_version_id;
        $other = app(BusinessFacts::class)->save($this->project, $this->owner, $this->factData(['name' => 'Booking', 'statement' => 'Cleaning can be booked online.']));
        $this->opportunity->update(['evidence_snapshot' => [...$this->opportunity->evidence_snapshot, 'diagnosis_mode' => 'reviewed_claim', 'confirmed_facts' => [['version_id' => $originalVersion]]]]);
        $proposal = app(Proposals::class)->begin($this->opportunity, $this->owner);
        app(BusinessFacts::class)->save($this->project, $this->owner, $this->factData(['expected_version_id' => $originalVersion, 'statement' => 'Cleaning now includes kitchens only.']), $this->fact);
        $patch = $this->proposalPatch();
        $patch['changes'][0]['fact_version_ids'] = [$other->current_version_id];
        try {
            app(Proposals::class)->generated($proposal, $proposal->generation_id, $patch, PipelineRun::query()->sole()->id);
            $this->fail('Stale discrepancy evidence must not produce a new revision.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('originating workflow', $exception->getMessage());
            $this->assertSame(0, $proposal->revisions()->count());
        }
        $run = PipelineRun::query()->sole();
        try {
            app(WritePageProposal::class)->handle(new StepContext($run, $this->project, $run->input, [], [], $this->models));
            $this->fail('A stale reviewed discrepancy must stop before the paid call.');
        } catch (TerminalStepFailure) {
            $this->assertSame([], $this->models->sent());
            $this->assertSame('failed', $proposal->fresh()->status);
        }
    }

    public function test_direct_generation_domain_refuses_changed_billing_without_creating_work(): void
    {
        $entitlements = app(Entitlements::class);
        $this->assertTrue($entitlements->for($this->project)->mayGenerate());
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['status' => 'canceled']);
        try {
            app(Proposals::class)->begin($this->opportunity, $this->owner);
            $this->fail('Direct domain entry must recheck billing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generation', $exception->errors());
        }
        $this->assertDatabaseCount('page_proposals', 0);
        $this->assertDatabaseCount('pipeline_runs', 0);
        $this->assertSame([], $this->models->sent());
    }

    public function test_queued_proposal_rechecks_canceled_expired_past_due_and_exhausted_cost_before_spending(): void
    {
        Queue::fake();
        $proposal = app(Proposals::class)->begin($this->opportunity, $this->owner);
        $run = PipelineRun::query()->sole();
        $context = new StepContext($run, $this->project, $run->input, [], [], $this->models);
        $subscription = ProjectSubscription::query()->where('project_id', $this->project->id)->firstOrFail();
        foreach ([['status' => 'canceled'], ['status' => 'past_due'], ['status' => 'trialing', 'trial_ends_at' => now()->subDay()], ['status' => 'active', 'limit_overrides' => ['cost_micros' => 0]]] as $changed) {
            $subscription->update(['status' => 'active', 'trial_ends_at' => null, 'limit_overrides' => []]);
            app(Entitlements::class)->forget($this->project);
            $this->assertTrue(app(Entitlements::class)->for($this->project)->mayGenerate());
            $subscription->update($changed);
            $proposal->update(['status' => 'drafting']);
            try {
                app(WritePageProposal::class)->handle($context);
                $this->fail('Queued paid work must stop when billing changes.');
            } catch (TerminalStepFailure) {
                $this->assertSame('failed', $proposal->refresh()->status);
                $this->assertSame([], $this->models->sent());
            }
        }
    }

    public function test_billing_refusal_blocks_regeneration_but_keeps_manual_revision_and_history_available(): void
    {
        $proposal = $this->generate();
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['status' => 'past_due']);
        $this->post('/proposals/'.$proposal->id.'/regenerate', ['revision_id' => $proposal->current_revision_id, 'reason' => 'Paid regeneration requested'])->assertSessionHasErrors('generation');
        $this->assertCount(1, $this->models->sent());
        $this->get('/proposals/'.$proposal->id)->assertOk();
        $this->post('/proposals/'.$proposal->id.'/revisions', ['revision_id' => $proposal->current_revision_id, 'reason' => 'Manual correction', 'active_seconds' => 20, ...$this->proposalPatch()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $proposal->revisions()->count());
        $this->assertCount(1, $this->models->sent());
    }

    /** @param array<string, mixed>|null $input */
    private function generate(?array $input = null): PageProposal
    {
        $this->models->willAnswer([json_encode($input ?? $this->proposalPatch(), JSON_THROW_ON_ERROR)]);
        $this->post('/opportunities/'.$this->opportunity->id.'/proposals')->assertRedirect();

        return PageProposal::query()->where('opportunity_id', $this->opportunity->id)->sole();
    }

    private function accept(PageProposal $proposal): void
    {
        $this->post('/proposals/'.$proposal->id.'/accept', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 20])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($proposal->current_revision_id, $proposal->refresh()->approved_revision_id);
    }

    private function authorize(PageProposal $proposal): PagePublication
    {
        $this->accept($proposal);
        $this->post('/proposals/'.$proposal->id.'/publish', ['revision_id' => $proposal->current_revision_id])->assertRedirect()->assertSessionHasNoErrors();

        return $proposal->publications()->where('revision_id', $proposal->current_revision_id)->sole();
    }

    private function recordApplied(PagePublication $publication): void
    {
        $this->post('/publications/'.$publication->id.'/applied', ['revision_id' => $publication->revision_id, 'applied_by_name' => 'Website operator', 'applied_at' => now()->toIso8601String(), 'application_note' => 'Applied only the reviewed paragraph in the site editor.', 'confirm_applied' => true])->assertRedirect()->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function proposalPatch(): array
    {
        $block = app(PageBlocks::class)->from($this->page->latestSnapshot)[0];

        return ['changes' => [['kind' => 'text_section', 'operation' => 'replace', 'locator' => $block['id'], 'before' => $block['text'], 'after' => 'Cleaning includes floors and bathrooms.', 'reason' => 'Answer the missing scope question with confirmed facts.', 'fact_version_ids' => [$this->fact->current_version_id], 'target_page_id' => null, 'anchor_text' => null]], 'missing_facts' => [], 'no_change_reason' => null];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function factData(array $overrides = []): array
    {
        return [...['name' => 'Included cleaning scope', 'statement' => 'Cleaning includes floors and bathrooms.', 'source_url' => 'https://example.com/en/service', 'source_note' => 'The owner checked the current service checklist.', 'status' => 'confirmed', 'confirm' => true, 'review_due_at' => now()->addDays(30)->toDateString()], ...$overrides];
    }

    private function html(string $scope = 'Our cleaning service.'): string
    {
        return '<html lang="en"><head><title>Cleaning service</title><meta name="description" content="Home cleaning"><link rel="canonical" href="/en/service"></head><body><main><p>'.$scope.'</p><p>Contact our team to book.</p></main></body></html>';
    }
}
