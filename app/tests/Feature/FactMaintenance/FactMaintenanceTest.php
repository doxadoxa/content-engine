<?php

declare(strict_types=1);

namespace Tests\Feature\FactMaintenance;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelRequest;
use App\Ai\UnmeteredSession;
use App\FactMaintenance\FactMaintenance;
use App\FactMaintenance\FactUsageImpacts;
use App\FactMaintenance\ReviewFactClaim;
use App\Models\BusinessFact;
use App\Models\FactMaintenanceCheck;
use App\Models\FactMaintenanceClaim;
use App\Models\FactMaintenanceResult;
use App\Models\FactMaintenanceReview;
use App\Models\FactUsageImpact;
use App\Models\PageOpportunity;
use App\Models\PageProposal;
use App\Models\PageProposalFact;
use App\Models\PageProposalRevision;
use App\Models\PagePublication;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\SitePage;
use App\Models\User;
use App\Pages\BusinessFacts;
use App\Pages\TrackedPages;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class FactMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private BusinessFact $fact;

    private SitePage $page;

    private FakeModelGateway $gateway;

    private string $body = '<main><h1>Our cleaning service</h1><p>We serve Porto.</p><form action="/book"><input name="email"></form></main><footer>We serve Porto.</footer>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['name' => 'Cleaning Point', 'website_url' => 'https://maintenance.test', 'default_locale' => 'en', 'locales' => ['en']]);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        app(CurrentProject::class)->set($this->project);
        $this->actingAs($this->owner);
        $this->fact = app(BusinessFacts::class)->save($this->project, $this->owner, $this->factData('We serve Lisbon only.'));
        Http::fake(['maintenance.test/*' => fn () => Http::response($this->html(), 200, ['Content-Type' => 'text/html'])]);
        $this->page = app(TrackedPages::class)->track($this->project, 'https://maintenance.test/service', 'en', 'commercial');
        $this->gateway = new FakeModelGateway;
        $this->app->instance(ModelGateway::class, $this->gateway);
        $this->answerWithClaims();
    }

    public function test_full_public_surfaces_keep_exact_dependencies_context_and_coverage(): void
    {
        $check = $this->check();
        $this->assertSame('complete', $check->status, $check->reason ?? '');
        $claims = FactMaintenanceClaim::query()->get();
        $this->assertNotEmpty($claims);
        $this->assertSame(['delivered_body_text'], $claims->pluck('source.kind')->unique()->values()->all());
        $this->assertSame($this->fact->current_version_id, $claims[0]->fact_version_id);
        $this->assertSame('We serve Porto.', $claims[0]->exact_quote);
        $result = FactMaintenanceResult::query()->sole();
        $this->assertSame('captured', $result->source_coverage['capture_status']);
        $this->assertSame($result->assessment['coverage']['total_characters'], $result->assessment['coverage']['assessed_characters']);
        $this->get('/fact-maintenance/'.$check->id)->assertOk()->assertInertia(fn ($page) => $page->where('claims.0.exact_quote', 'We serve Porto.')->where('selected_facts.0.statement', 'We serve Lisbon only.'));
    }

    public function test_same_request_and_retried_worker_do_not_repeat_paid_work(): void
    {
        $input = $this->input();
        $check = app(FactMaintenance::class)->start($this->project, $this->owner, $input)[0];
        $this->assertSame($check->id, app(FactMaintenance::class)->start($this->project, $this->owner, $input)[0]->id);
        $this->perform($check);
        $calls = count($this->gateway->sent());
        $this->perform($check->fresh());
        $this->assertSame($calls, count($this->gateway->sent()));
        $this->assertSame(1, FactMaintenanceResult::query()->count());
        $this->assertSame(1, FactMaintenanceCheck::query()->count());
    }

    public function test_missing_or_partial_surface_capture_remains_unknown(): void
    {
        $this->body .= '<script type="application/ld+json">{broken JSON</script>';
        $check = $this->check();
        $this->assertSame('partial', $check->status);
        $result = FactMaintenanceResult::query()->sole();
        $this->assertNotEmpty($result->source_coverage['omitted']);
        $this->assertSame('partial', $result->source_coverage['capture_status']);
    }

    public function test_fact_change_flags_prior_usages_and_preserves_the_original_quote(): void
    {
        $this->check();
        $claim = FactMaintenanceClaim::query()->firstOrFail();
        $old = $this->fact->current_version_id;
        $this->fact = app(BusinessFacts::class)->save($this->project, $this->owner, [...$this->factData('We serve Lisbon and Porto.'), 'expected_version_id' => $old], $this->fact);
        $impact = FactUsageImpact::query()->where('origin_id', $claim->id)->sole();
        $this->assertSame($old, $impact->previous_fact_version_id);
        $this->assertSame($this->fact->current_version_id, $impact->new_fact_version_id);
        $this->assertSame('We serve Porto.', $claim->fresh()->exact_quote);
        $this->assertNotNull(app(FactMaintenance::class)->currentReason(FactMaintenanceCheck::query()->firstOrFail()));
        app(FactUsageImpacts::class)->reconcile();
        $this->assertSame(1, FactUsageImpact::query()->where('origin_id', $claim->id)->count());
    }

    public function test_a_changed_fact_during_model_work_keeps_cost_evidence_but_blocks_actions(): void
    {
        $changed = false;
        $this->gateway->willAnswerUsing(function (ModelRequest $request) use (&$changed): string {
            if (! $changed) {
                $changed = true;
                BusinessFact::query()->whereKey($this->fact->id)->update(['current_version_id' => null]);
            }

            return $this->response($request);
        });
        $check = $this->check();
        $this->assertSame('stale', $check->status);
        $result = FactMaintenanceResult::query()->sole();
        $this->assertNotEmpty($result->assessment['checker']['calls']);
        $this->assertSame('unavailable', $result->assessment['status']);
        $this->assertSame(0, FactMaintenanceClaim::query()->count());
    }

    public function test_page_pause_during_model_work_cannot_become_actionable(): void
    {
        $this->gateway->willAnswerUsing(function (ModelRequest $request): string {
            SitePage::query()->whereKey($this->page->id)->update(['tracked_at' => null]);

            return $this->response($request);
        });
        $check = $this->check();
        $this->assertSame('stale', $check->status);
        $this->assertNull($this->page->fresh()->tracked_at);
        $claim = FactMaintenanceClaim::query()->firstOrFail();
        $this->post('/fact-maintenance/claims/'.$claim->id.'/review', $this->reviewInput('correct'))->assertConflict();
        $this->assertSame(0, PageOpportunity::query()->count());
    }

    public function test_review_is_explicit_idempotent_and_dismissal_requires_reopening(): void
    {
        $this->check();
        $claim = FactMaintenanceClaim::query()->firstOrFail();
        $input = $this->reviewInput('dismiss');
        $review = app(ReviewFactClaim::class)->review($claim, $this->owner, $input);
        $this->assertSame($review->id, app(ReviewFactClaim::class)->review($claim, $this->owner, $input)->id);
        $this->assertSame(1, FactMaintenanceReview::query()->count());
        $this->post('/fact-maintenance/claims/'.$claim->id.'/review', $this->reviewInput('correct'))->assertConflict();
        $this->post('/fact-maintenance/claims/'.$claim->id.'/review', [...$this->reviewInput('correct'), 'expected_review_id' => $review->id])->assertConflict();
        $this->post('/fact-maintenance/claims/'.$claim->id.'/review', [...$this->reviewInput('reopen'), 'expected_review_id' => $review->id])->assertRedirect();
        $this->assertSame(0, PagePublication::query()->count());
    }

    public function test_supported_owner_correction_uses_current_fact_and_creates_only_an_opportunity(): void
    {
        $this->check();
        $claim = FactMaintenanceClaim::query()->firstOrFail();
        $review = app(ReviewFactClaim::class)->review($claim, $this->owner, $this->reviewInput('correct'));
        $opportunity = PageOpportunity::query()->sole();
        $this->assertSame($opportunity->id, $review->evidence['opportunity_id']);
        $this->assertSame('fact_maintenance', $opportunity->evidence_snapshot['origin_type']);
        $this->assertSame($this->fact->current_version_id, $opportunity->evidence_snapshot['confirmed_facts'][0]['version_id']);
        $this->assertSame(0, PageProposal::query()->count());
        $this->assertSame(0, PagePublication::query()->count());
    }

    public function test_footer_claim_produces_a_concrete_assisted_handoff_without_publishing(): void
    {
        $this->body = '<main><h1>Cleaning service</h1><p>Book a visit.</p></main><footer>We serve Porto.</footer>';
        $check = $this->check();
        $claim = FactMaintenanceClaim::query()->sole();
        $this->post('/fact-maintenance/claims/'.$claim->id.'/review', $this->reviewInput('correct'))->assertUnprocessable();
        $review = app(ReviewFactClaim::class)->review($claim, $this->owner, [...$this->reviewInput('handoff'), 'replacement_text' => 'We serve Lisbon only.', 'instructions' => 'Update this exact footer statement in the English site layout. Preserve all footer links.']);
        $this->assertSame('We serve Porto.', $review->evidence['handoff']['before']);
        $this->assertSame('We serve Lisbon only.', $review->evidence['handoff']['after']);
        $this->assertSame('en', $review->evidence['handoff']['locale']);
        $this->get('/fact-maintenance/'.$check->id)->assertOk()->assertInertia(fn ($page) => $page->where('claims.0.correction_mode', 'assisted')->where('claims.0.reviews.0.action', 'handoff'));
        $this->assertSame(0, PageOpportunity::query()->count());
        $this->assertSame(0, PagePublication::query()->count());
    }

    public function test_recheck_records_quote_absence_without_claiming_the_fact_was_corrected(): void
    {
        $first = $this->check();
        $this->body = '<main><h1>Cleaning service</h1><p>Ask us about availability.</p></main>';
        $next = $this->check();
        $this->assertNotSame($first->id, $next->id);
        $comparison = FactMaintenanceResult::query()->where('check_id', $next->id)->sole()->comparisons[0];
        $this->assertSame('quote_removed', $comparison['observation']);
        $this->assertStringContainsString('does not establish', $comparison['meaning']);
        $this->assertNotSame($first->source_snapshot_id, $next->source_snapshot_id);
        $this->assertSame(2, FactMaintenanceResult::query()->count());
    }

    public function test_retracted_evidence_never_supplies_a_new_positive_correction(): void
    {
        $check = $this->check();
        $claim = FactMaintenanceClaim::query()->firstOrFail();
        $this->fact = app(BusinessFacts::class)->save($this->project, $this->owner, [...$this->factData('Earlier coverage withdrawn.'), 'status' => 'retracted', 'expected_version_id' => $this->fact->current_version_id], $this->fact);
        $this->post('/fact-maintenance/claims/'.$claim->id.'/review', $this->reviewInput('correct'))->assertConflict();
        $this->post('/fact-maintenance', $this->input())->assertConflict();
        $this->assertSame('retracted', FactUsageImpact::query()->firstOrFail()->evidence['new_status']);
        $this->assertSame($check->source_snapshot_id, $claim->source_snapshot_id);
        $this->assertSame(0, PageOpportunity::query()->count());
    }

    public function test_reads_never_buy_work_and_other_tenants_or_nonowners_cannot_start_or_review(): void
    {
        $this->get('/fact-maintenance')->assertOk();
        $this->assertCount(0, $this->gateway->sent());
        $viewer = User::factory()->create();
        $viewer->projects()->attach($this->project, ['role' => 'editor']);
        $this->actingAs($viewer)->post('/fact-maintenance', $this->input())->assertForbidden();
        $check = $this->check();
        $claim = FactMaintenanceClaim::query()->firstOrFail();
        $this->post('/fact-maintenance/claims/'.$claim->id.'/review', $this->reviewInput('dismiss'))->assertForbidden();
        $other = Project::factory()->create();
        $otherOwner = User::factory()->create();
        $otherOwner->projects()->attach($other, ['role' => 'owner']);
        app(CurrentProject::class)->set($other);
        $this->actingAs($otherOwner)->get('/fact-maintenance/'.$check->id)->assertNotFound();
    }

    public function test_historical_results_and_check_inputs_cannot_be_rewritten(): void
    {
        $check = $this->check();
        $this->expectException(\LogicException::class);
        $check->update(['specification' => ['fact_version_ids' => []]]);
    }

    public function test_fact_changes_flag_published_references_even_when_the_receipt_arrives_later(): void
    {
        $check = $this->check();
        $claim = FactMaintenanceClaim::query()->firstOrFail();
        $review = app(ReviewFactClaim::class)->review($claim, $this->owner, $this->reviewInput('correct'));
        $proposal = PageProposal::query()->create(['opportunity_id' => $review->evidence['opportunity_id'], 'site_page_id' => $this->page->id, 'status' => 'approved', 'created_by' => $this->owner->id]);
        $revision = PageProposalRevision::query()->create(['proposal_id' => $proposal->id, 'site_page_id' => $this->page->id, 'source_snapshot_id' => $check->source_snapshot_id, 'number' => 1,
            'canonical_url' => $this->page->canonical_url, 'locale' => 'en', 'changes' => [], 'patch_hash' => hash('sha256', 'fixture'), 'evidence_snapshot' => [], 'measurement_plan' => [], 'missing_facts' => [], 'created_by' => $this->owner->id]);
        PageProposalFact::query()->create(['proposal_id' => $proposal->id, 'revision_id' => $revision->id, 'business_fact_id' => $this->fact->id, 'fact_version_id' => $this->fact->current_version_id]);
        $publication = PagePublication::query()->create(['proposal_id' => $proposal->id, 'revision_id' => $revision->id, 'site_page_id' => $this->page->id, 'delivery_id' => (string) Str::uuid(), 'authorized_at' => now(), 'status' => 'awaiting_operator']);
        $old = $this->fact->current_version_id;
        $this->fact = app(BusinessFacts::class)->save($this->project, $this->owner, [...$this->factData('Current scope changed.'), 'expected_version_id' => $old], $this->fact);
        $this->assertSame(0, FactUsageImpact::query()->where('origin_type', 'publication')->count());
        $publication->update(['applied_at' => now(), 'status' => 'applied_unverified']);
        $this->project->update(['status' => 'paused']);
        $calls = count($this->gateway->sent());
        $command = $this->artisan('facts:reconcile-maintenance');
        if (! $command instanceof PendingCommand) {
            $this->fail('Expected a pending command so its execution is verified.');
        }
        $command->assertSuccessful()->run();
        $impact = FactUsageImpact::query()->where('origin_type', 'publication')->sole();
        $this->assertSame($publication->id, $impact->origin_id);
        $this->assertSame($old, $impact->previous_fact_version_id);
        $this->assertSame($proposal->id, $impact->evidence['proposal_id']);
        $this->assertCount($calls, $this->gateway->sent());
    }

    public function test_lost_attempts_are_not_retried_and_explicit_new_checks_keep_both_histories(): void
    {
        Queue::fake();
        $check = app(FactMaintenance::class)->start($this->project, $this->owner, $this->input())[0];
        $check->update(['status' => 'running', 'attempted_at' => now()->subMinutes(32)]);
        $command = $this->artisan('facts:reconcile-maintenance');
        if (! $command instanceof PendingCommand) {
            $this->fail('Expected a pending command so its execution is verified.');
        }
        $command->assertSuccessful()->run();
        $this->assertSame('indeterminate', $check->refresh()->status);
        $this->assertCount(0, $this->gateway->sent());
        $this->perform($check);
        $this->assertCount(0, $this->gateway->sent());
        $new = $this->check();
        $this->assertSame('complete', $new->status);
        $this->assertSame(2, FactMaintenanceCheck::query()->count());
        $this->assertSame('indeterminate', $check->refresh()->status);
    }

    public function test_new_public_capture_blocks_actions_against_the_old_snapshot(): void
    {
        $this->check();
        $claim = FactMaintenanceClaim::query()->firstOrFail();
        app(TrackedPages::class)->capture($this->project, $this->page->fresh());
        $this->post('/fact-maintenance/claims/'.$claim->id.'/review', $this->reviewInput('correct'))->assertConflict();
        $this->assertSame(0, PageOpportunity::query()->count());
        $this->assertSame(0, FactMaintenanceReview::query()->count());
    }

    public function test_recheck_keeps_the_last_evidenced_baseline_after_an_interrupted_capture(): void
    {
        $first = $this->check();
        $claim = FactMaintenanceClaim::query()->where('check_id', $first->id)->firstOrFail();
        Queue::fake();
        $interrupted = app(FactMaintenance::class)->start($this->project, $this->owner, $this->input())[0];
        $page = app(TrackedPages::class)->capture($this->project, $this->page->fresh());
        $interrupted->update(['status' => 'indeterminate', 'source_snapshot_id' => $page->latestSnapshot->id, 'attempted_at' => now(), 'finished_at' => now()]);
        $fresh = $this->check();
        $result = FactMaintenanceResult::query()->where('check_id', $fresh->id)->sole();
        $this->assertSame($claim->id, $result->comparisons[0]['previous_claim_id']);
        $this->assertSame('remaining', $result->comparisons[0]['observation']);
        $this->assertSame('indeterminate', $interrupted->fresh()->status);
    }

    public function test_reworded_claim_is_a_current_checker_candidate_not_an_automatic_correction_verdict(): void
    {
        $this->check();
        $this->body = '<main><h1>Cleaning service</h1><p>We cover Lisbon only.</p></main>';
        $this->gateway->willAnswerUsing(function (ModelRequest $request): string {
            $source = json_decode($request->prompt, true, flags: JSON_THROW_ON_ERROR)['source'];
            $quote = 'We cover Lisbon only.';
            $findings = str_contains($source['text'], $quote) ? [['exact_quote' => $quote, 'relation' => 'supported', 'fact_version_id' => $this->fact->current_version_id, 'reason' => 'The new wording matches the confirmed service area.', 'reference_ids' => []]] : [];

            return json_encode(['assessed_entire_section' => true, 'findings' => $findings], JSON_THROW_ON_ERROR);
        });
        $check = $this->check();
        $comparison = FactMaintenanceResult::query()->where('check_id', $check->id)->sole()->comparisons[0];
        $this->assertSame('changed_claim_candidate', $comparison['observation']);
        $this->assertSame('We cover Lisbon only.', $comparison['current_candidates'][0]['exactQuote']);
        $this->assertStringContainsString('Review', $comparison['meaning']);
        $this->assertSame(0, FactMaintenanceReview::query()->count());
    }

    public function test_structured_data_claims_retain_json_source_and_require_assistance(): void
    {
        $this->body = '<main><h1>Cleaning service</h1></main><script type="application/ld+json">{"@type":"LocalBusiness","areaServed":"Porto"}</script>';
        $this->gateway->willAnswerUsing(function (ModelRequest $request): string {
            $source = json_decode($request->prompt, true, flags: JSON_THROW_ON_ERROR)['source'];
            $quote = '"areaServed":"Porto"';
            $findings = str_contains($source['text'], $quote) ? [['exact_quote' => $quote, 'relation' => 'contradicted', 'fact_version_id' => $this->fact->current_version_id, 'reason' => 'The published structured data identifies the wrong service area.', 'reference_ids' => []]] : [];

            return json_encode(['assessed_entire_section' => true, 'findings' => $findings], JSON_THROW_ON_ERROR);
        });
        $check = $this->check();
        $claim = FactMaintenanceClaim::query()->sole();
        $this->assertSame('json_ld', $claim->source['kind']);
        $this->get('/fact-maintenance/'.$check->id)->assertOk()->assertInertia(fn ($page) => $page->where('claims.0.correction_mode', 'assisted'));
        $this->post('/fact-maintenance/claims/'.$claim->id.'/review', $this->reviewInput('correct'))->assertUnprocessable();
        $this->assertSame(0, PageOpportunity::query()->count());
    }

    public function test_registered_pipeline_runs_the_check_and_records_model_usage(): void
    {
        $check = app(FactMaintenance::class)->start($this->project, $this->owner, $this->input())[0];
        app(FactMaintenance::class)->dispatch($this->project);
        $this->assertSame('complete', $check->refresh()->status, $check->reason ?? '');
        $this->assertNotNull($check->pipeline_run_id);
        $this->assertGreaterThan(0, (int) PipelineStep::query()->where('pipeline_run_id', $check->pipeline_run_id)->sum('input_tokens'));
        $this->assertSame(1, FactMaintenanceResult::query()->count());
    }

    public function test_dispatch_keeps_one_pipeline_link_when_reconciliation_repeats(): void
    {
        Queue::fake();
        $check = app(FactMaintenance::class)->start($this->project, $this->owner, $this->input())[0];
        app(FactMaintenance::class)->dispatch($this->project);
        $runId = $check->refresh()->pipeline_run_id;
        app(FactMaintenance::class)->dispatch($this->project);
        $this->assertSame($runId, $check->refresh()->pipeline_run_id);
        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'fact_maintenance')->count());
        $this->assertCount(0, $this->gateway->sent());
    }

    public function test_billing_refusal_blocks_new_checks_without_hiding_existing_evidence(): void
    {
        $check = $this->check();
        $calls = count($this->gateway->sent());
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['status' => 'canceled']);
        $this->post('/fact-maintenance', $this->input())->assertSessionHasErrors('check');
        $this->get('/fact-maintenance/'.$check->id)->assertOk();
        $this->assertCount($calls, $this->gateway->sent());
        $this->assertSame(1, FactMaintenanceCheck::query()->count());
    }

    public function test_billing_changed_while_queued_stops_before_model_work(): void
    {
        Queue::fake();
        $check = app(FactMaintenance::class)->start($this->project, $this->owner, $this->input())[0];
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['status' => 'past_due']);
        $this->perform($check);
        $this->assertSame('unavailable', $check->refresh()->status);
        $this->assertNull($check->attempted_at);
        $this->assertCount(0, $this->gateway->sent());
    }

    public function test_database_rejects_a_claim_or_check_bound_to_another_page_or_tenant(): void
    {
        $check = $this->check();
        $claim = FactMaintenanceClaim::query()->firstOrFail();
        $other = Project::factory()->create(['website_url' => 'https://maintenance.test']);
        $otherPage = app(CurrentProject::class)->run($other, fn () => app(TrackedPages::class)->track($other, 'https://maintenance.test/service', 'en', 'commercial'));
        $attempts = [
            fn () => DB::table('fact_maintenance_checks')->where('id', $check->id)->update(['source_snapshot_id' => $otherPage->latestSnapshot->id]),
            fn () => DB::table('fact_maintenance_claims')->where('id', $claim->id)->update(['site_page_id' => $otherPage->id]),
            fn () => DB::table('fact_maintenance_claims')->where('id', $claim->id)->update(['source_snapshot_id' => $otherPage->latestSnapshot->id]),
            fn () => DB::table('fact_maintenance_claims')->where('id', $claim->id)->update(['project_id' => $other->id]),
        ];
        foreach ($attempts as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The database must reject a cross-page or cross-tenant evidence reference.');
            } catch (QueryException $error) {
                $this->assertSame('23503', $error->errorInfo[0]);
            }
        }
        $this->assertSame($this->page->id, $claim->fresh()->site_page_id);
        $this->assertSame($check->source_snapshot_id, $claim->fresh()->source_snapshot_id);
    }

    /** @return array<string,mixed> */
    private function factData(string $statement): array
    {
        return ['name' => 'Service area', 'statement' => $statement, 'source_url' => 'https://maintenance.test/service', 'source_note' => 'Owner checked the actual service coverage.',
            'status' => 'confirmed', 'confirm' => true, 'review_due_at' => now()->addMonth()->toDateString()];
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        return ['request_key' => (string) Str::uuid(), 'page_ids' => [$this->page->id], 'fact_version_ids' => [$this->fact->current_version_id]];
    }

    /** @return array<string,mixed> */
    private function reviewInput(string $action): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_review_id' => null, 'action' => $action, 'reason' => 'I reviewed the quoted claim and confirmed service area.', 'confirm' => true, 'fact_version_id' => $this->fact->current_version_id];
    }

    private function check(): FactMaintenanceCheck
    {
        $check = app(FactMaintenance::class)->start($this->project, $this->owner, $this->input())[0];
        $this->perform($check);

        return $check->fresh();
    }

    private function perform(FactMaintenanceCheck $check): void
    {
        app(FactMaintenance::class)->perform($check, new UnmeteredSession($this->gateway));
    }

    private function answerWithClaims(): void
    {
        $this->gateway->willAnswerUsing(fn (ModelRequest $request): string => $this->response($request));
    }

    private function response(ModelRequest $request): string
    {
        $source = json_decode($request->prompt, true, flags: JSON_THROW_ON_ERROR)['source'];
        $quote = 'We serve Porto.';
        $offset = mb_strpos($source['text'], $quote);
        $findings = $offset === false ? [] : [['exact_quote' => $quote, 'start_codepoint' => $offset, 'relation' => 'contradicted', 'fact_version_id' => $this->fact->current_version_id,
            'reason' => 'The quoted Porto claim conflicts with the confirmed Lisbon-only scope.', 'reference_ids' => []]];

        return json_encode(['assessed_entire_section' => true, 'findings' => $findings], JSON_THROW_ON_ERROR);
    }

    private function html(): string
    {
        return '<html lang="en"><head><title>Cleaning Point</title><meta name="description" content="Book a cleaning visit."><link rel="canonical" href="https://maintenance.test/service"></head><body>'.$this->body.'</body></html>';
    }
}
