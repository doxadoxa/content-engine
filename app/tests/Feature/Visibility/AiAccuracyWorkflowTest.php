<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelRequest;
use App\Models\AiAccuracyAssessment;
use App\Models\AiAccuracyFinding;
use App\Models\AiAccuracyReview;
use App\Models\AiCorrectionAction;
use App\Models\AiCorrectionRecheck;
use App\Models\AiCorrectionUpdate;
use App\Models\AiSamplingAnswer;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\PageOpportunity;
use App\Models\PagePublication;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\Accuracy\AccuracyAssessments;
use App\Visibility\Accuracy\AccuracyReport;
use App\Visibility\Accuracy\CorrectionActions;
use App\Visibility\Contracts\LlmVisibilityGateway;
use App\Visibility\FakeLlmVisibility;
use App\Visibility\Sampling\SamplingRuns;
use App\Visibility\Sampling\SamplingSets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AiAccuracyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTE = 'Cleaning Point serves Porto.';

    private Project $project;

    private User $owner;

    private BusinessFactVersion $fact;

    private AiSamplingAnswer $answer;

    private FakeModelGateway $checker;

    private FakeLlmVisibility $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['name' => 'Cleaning Point', 'website_url' => 'https://example.com', 'market' => 'pt', 'default_locale' => 'en', 'locales' => ['en']]);
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $businessFact = BusinessFact::query()->create(['name' => 'Service area']);
        $this->fact = BusinessFactVersion::query()->create(['business_fact_id' => $businessFact->id, 'version' => 1, 'statement' => 'Cleaning Point serves Lisbon only.',
            'source_url' => 'https://example.com/services', 'source_note' => 'Owner confirmed current service area.', 'status' => 'confirmed', 'confirmed_by' => $this->owner->id,
            'confirmed_at' => now(), 'review_due_at' => now()->addMonth(), 'created_by' => $this->owner->id]);
        $businessFact->forceFill(['current_version_id' => $this->fact->id])->save();
        /** @var FakeModelGateway $checker */
        $checker = app(ModelGateway::class);
        $this->checker = $checker;
        $checker->willAnswerUsing(function (ModelRequest $request): string {
            $source = json_decode($request->prompt, true, flags: JSON_THROW_ON_ERROR)['source'];
            $quote = str_contains($source['text'], self::QUOTE) ? self::QUOTE : null;

            return json_encode(['assessed_entire_section' => true, 'findings' => $quote === null ? [] : [['exact_quote' => $quote, 'relation' => 'contradicted', 'fact_version_id' => $this->fact->id,
                'reason' => 'Porto service conflicts with the current Lisbon-only service area.', 'reference_ids' => []]]], JSON_THROW_ON_ERROR);
        });
        /** @var FakeLlmVisibility $provider */
        $provider = app(LlmVisibilityGateway::class);
        $this->provider = $provider;
        $provider->willAnswer('chat_gpt', 'Where does Cleaning Point work?', self::QUOTE);
        // A billed question set must cover all four supported services; only ChatGPT is scripted with the inaccurate claim.
        config()->set('visibility.platforms', ['chat_gpt' => ['model' => 'pinned-model', 'accepts_country' => true], 'gemini' => ['model' => 'gemini-model', 'accepts_country' => false],
            'claude' => ['model' => 'claude-model'], 'perplexity' => ['model' => 'perplexity-model']]);
        config()->set('queue.default', 'sync');
        Cache::flush();
        $set = app(SamplingSets::class)->save($this->project, $this->owner, ['reason' => 'Test accuracy instrument', 'prompts' => [['text' => 'Where does Cleaning Point work?', 'locale' => 'en', 'intent' => 'learning', 'purpose' => 'accuracy']]]);
        $run = app(SamplingRuns::class)->start($this->project, $set, (string) Str::uuid(), $this->owner);
        app(SamplingRuns::class)->dispatch($this->project, $run);
        $this->assertSame(4, AiSamplingAnswer::query()->count());
        $this->answer = AiSamplingAnswer::query()->where('full_text', self::QUOTE)->sole();
    }

    #[Test]
    public function assessment_pins_full_input_current_facts_and_metered_checker_provenance(): void
    {
        $assessment = $this->assess();
        $finding = AiAccuracyFinding::query()->sole();
        $this->assertSame('complete', $assessment->status);
        $this->assertSame(self::QUOTE, $assessment->sections[0]['text']);
        $this->assertSame($this->fact->id, $assessment->fact_versions[0]['id']);
        $this->assertSame(self::QUOTE, $finding->evidence['exactQuote']);
        $this->assertSame('fake', $assessment->result['checker']['calls'][0]['provider']);
        $this->assertGreaterThan(0, PipelineStep::query()->where('step_key', 'assess_recorded_factual_claims')->sum('cost_micros'));
        $report = app(AccuracyReport::class)->answer($this->answer);
        $this->assertTrue($report['assessments'][0]['facts_current']);
        $this->assertFalse($report['assessments'][0]['findings'][0]['can_correct']);
        $this->expectException(LogicException::class);
        $assessment->update(['result' => ['status' => 'changed']]);
    }

    #[Test]
    public function missing_current_facts_produces_unavailable_without_a_paid_checker_call(): void
    {
        BusinessFact::query()->update(['current_version_id' => null]);
        $assessment = $this->assess();
        $this->assertSame('unavailable', $assessment->status);
        $this->assertCount(0, $this->checker->sent());
        $this->assertSame(0, AiAccuracyFinding::query()->count());
    }

    #[Test]
    public function correction_requires_owner_confirmation_and_external_handoff_never_sends(): void
    {
        Http::fake();
        Mail::fake();
        $this->assess();
        $finding = AiAccuracyFinding::query()->sole();
        $this->actingAs($this->owner)->post(route('ai-accuracy.handoff', $finding))->assertConflict();
        $this->actingAs($this->owner)->post(route('ai-accuracy.review', $finding), ['decision' => 'confirmed', 'reason' => 'I verified the exact statement against our current service area.'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('ai-accuracy.handoff', $finding))->assertRedirect();
        $action = AiCorrectionAction::query()->sole();
        $this->assertStringContainsString(self::QUOTE, $action->draft);
        $this->assertStringContainsString($this->fact->statement, $action->draft);
        $this->assertStringContainsString((string) $this->fact->source_url, $action->draft);
        $this->actingAs($this->owner)->post(route('ai-accuracy.record-handoff', $action), ['status' => 'owner_submitted', 'note' => 'Submitted through the provider feedback form.'])->assertRedirect();
        $this->assertSame('owner_submitted', AiCorrectionUpdate::query()->sole()->status);
        $this->assertSame(0, PagePublication::query()->count());
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }

    #[Test]
    public function a_correct_owned_page_does_not_receive_an_invented_correction(): void
    {
        $finding = $this->confirmedFinding();
        $page = $this->page($this->fact->statement);
        $assessment = app(AccuracyAssessments::class)->page($this->owner, $finding, $page, (string) Str::uuid());
        app(AccuracyAssessments::class)->dispatch($this->project, $assessment);
        $this->assertSame('complete', $assessment->refresh()->status);
        $this->assertSame(0, AiAccuracyFinding::query()->where('assessment_id', $assessment->id)->count());
        $this->assertSame(0, PageOpportunity::query()->count());
        $action = app(CorrectionActions::class)->handoff($this->owner, $finding);
        $this->assertSame('external_handoff', $action->kind);
    }

    #[Test]
    public function a_separately_reviewed_owned_page_discrepancy_enters_the_existing_proposal_plan(): void
    {
        [$original, $pageFinding] = $this->pageFinding();
        $this->actingAs($this->owner)->post(route('ai-accuracy.owned-page', $pageFinding))->assertConflict();
        app(AccuracyAssessments::class)->review($this->owner, $pageFinding, ['decision' => 'confirmed', 'reason' => 'The current public page states the wrong service area.']);
        $this->actingAs($this->owner)->post(route('ai-accuracy.owned-page', $pageFinding))->assertRedirect(route('opportunities.index'));
        $opportunity = PageOpportunity::query()->sole();
        $this->assertSame('reviewed_claim', $opportunity->evidence_snapshot['diagnosis_mode']);
        $this->assertSame($original->id, $opportunity->evidence_snapshot['origin_id']);
        $this->assertSame(self::QUOTE, $opportunity->evidence_snapshot['quoted_excerpt']);
        $this->assertSame($this->fact->id, $opportunity->evidence_snapshot['confirmed_facts'][0]['version_id']);
        $this->assertSame(0, PagePublication::query()->count());
        app(CorrectionActions::class)->ownedPage($this->owner, $pageFinding);
        $this->assertSame(1, PageOpportunity::query()->count());
        $this->assertSame(1, AiCorrectionAction::query()->count());
    }

    #[Test]
    public function changed_source_or_withdrawn_fact_refuses_correction_without_erasing_evidence(): void
    {
        [, $finding, $page] = $this->pageFinding();
        app(AccuracyAssessments::class)->review($this->owner, $finding, ['decision' => 'confirmed', 'reason' => 'Confirmed before source changed.']);
        $page->update(['tracked_at' => null]);
        $this->actingAs($this->owner)->post(route('ai-accuracy.owned-page', $finding))->assertConflict();
        BusinessFact::query()->update(['current_version_id' => null]);
        $this->actingAs($this->owner)->post(route('ai-accuracy.review', $finding), ['decision' => 'confirmed', 'reason' => 'A stale confirmation attempt.'])->assertConflict();
        $this->assertSame(0, PageOpportunity::query()->count());
        $this->assertSame(2, AiAccuracyAssessment::query()->count());
        $this->assertFalse(app(AccuracyReport::class)->answer($this->answer)['assessments'][0]['facts_current']);
    }

    #[Test]
    public function recheck_reuses_the_original_question_and_records_a_separate_same_day_observation(): void
    {
        $action = app(CorrectionActions::class)->handoff($this->owner, $this->confirmedFinding());
        $key = (string) Str::uuid();
        $run = app(CorrectionActions::class)->recheck($this->owner, $action, $key);
        app(SamplingRuns::class)->dispatch($this->project, $run);
        $again = app(CorrectionActions::class)->recheck($this->owner, $action, $key);
        $this->assertSame($run->id, $again->id);
        $this->assertSame(1, AiCorrectionRecheck::query()->count());
        $this->assertSame(5, AiSamplingAnswer::query()->count());
        $asked = array_values(array_filter($this->provider->asked(), fn (array $call): bool => $call['platform'] === 'chat_gpt'));
        $this->assertCount(5, $this->provider->asked());
        $this->assertCount(2, $asked);
        $this->assertSame($asked[0], $asked[1]);
        $this->assertSame('complete', $run->refresh()->status);
        $newAnswer = AiSamplingAnswer::query()->where('full_text', self::QUOTE)->whereKeyNot($this->answer->id)->sole();
        $this->assertSame('complete', AiAccuracyAssessment::query()->where('answer_id', $newAnswer->id)->sole()->status);
        $this->assertCount(2, $this->checker->sent());
    }

    #[Test]
    public function assessment_submissions_are_idempotent_and_editors_cannot_start_paid_work(): void
    {
        $key = (string) Str::uuid();
        $first = app(AccuracyAssessments::class)->answer($this->owner, $this->answer, $key);
        app(AccuracyAssessments::class)->dispatch($this->project, $first);
        $second = app(AccuracyAssessments::class)->answer($this->owner, $this->answer, $key);
        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->checker->sent());
        $editor = User::factory()->create();
        $editor->projects()->attach($this->project, ['role' => 'editor']);
        $this->actingAs($editor)->post(route('ai-accuracy.assess', $this->answer), ['request_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertCount(1, $this->checker->sent());
    }

    #[Test]
    public function malformed_checker_output_retains_usage_and_cannot_be_rebought_by_a_retry(): void
    {
        $this->checker->willAnswerUsing(fn (): string => 'not valid JSON');
        $assessment = $this->assess();
        $this->assertSame('unavailable', $assessment->status);
        $this->assertSame(0, AiAccuracyFinding::query()->count());
        $this->assertCount(1, $assessment->result['checker']['calls']);
        app(PipelineRunner::class)->start('ai_accuracy', $this->project, ['assessment_id' => $assessment->id]);
        $this->assertCount(1, $this->checker->sent());
    }

    #[Test]
    public function a_withdrawn_finding_cannot_support_a_handoff_marked_submitted(): void
    {
        $finding = $this->confirmedFinding();
        $action = app(CorrectionActions::class)->handoff($this->owner, $finding);
        app(AccuracyAssessments::class)->review($this->owner, $finding, ['decision' => 'dismissed', 'reason' => 'I withdrew my earlier assessment.', 'expected_review_id' => AiAccuracyReview::query()->where('finding_id', $finding->id)->latest('id')->firstOrFail()->id]);
        $this->actingAs($this->owner)->post(route('ai-accuracy.record-handoff', $action), ['status' => 'owner_submitted', 'note' => 'A stale form submission.'])->assertConflict();
        $this->assertSame(0, AiCorrectionUpdate::query()->count());
        $this->assertSame(2, AiAccuracyReview::query()->where('finding_id', $finding->id)->count());
    }

    #[Test]
    public function another_project_cannot_read_or_review_the_recorded_answer(): void
    {
        $finding = $this->confirmedFinding();
        $other = Project::factory()->create();
        $otherOwner = User::factory()->create();
        $otherOwner->projects()->attach($other, ['role' => 'owner']);
        app(CurrentProject::class)->set($other);
        $this->actingAs($otherOwner)->get(route('ai-sampling.answer', $this->answer))->assertNotFound();
        $this->actingAs($otherOwner)->post(route('ai-accuracy.review', $finding), ['decision' => 'confirmed', 'reason' => 'Cross-tenant request.'])->assertNotFound();
    }

    #[Test]
    public function stale_reviews_cannot_reverse_a_dismissal_and_reopening_is_explicit(): void
    {
        $finding = $this->confirmedFinding();
        $first = AiAccuracyReview::query()->sole();
        $dismissal = app(AccuracyAssessments::class)->review($this->owner, $finding, ['decision' => 'dismissed', 'reason' => 'The first review missed context.', 'expected_review_id' => $first->id]);
        $this->actingAs($this->owner)->post(route('ai-accuracy.review', $finding), ['decision' => 'confirmed', 'reason' => 'Stale tab.', 'expected_review_id' => $first->id])->assertConflict();
        $this->actingAs($this->owner)->post(route('ai-accuracy.review', $finding), ['decision' => 'confirmed', 'reason' => 'Current tab without reopening.', 'expected_review_id' => $dismissal->id])->assertConflict();
        $this->assertSame(2, AiAccuracyReview::query()->count());
        $this->actingAs($this->owner)->post(route('ai-accuracy.review', $finding), ['decision' => 'confirmed', 'reason' => 'I checked the dismissed concern and confirm again.', 'expected_review_id' => $dismissal->id, 'reopen' => true])->assertRedirect();
        $this->assertCount(3, app(AccuracyReport::class)->answer($this->answer)['assessments'][0]['findings'][0]['review_history']);
    }

    #[Test]
    public function a_footer_discrepancy_creates_a_reviewable_assisted_handoff_without_an_unsupported_proposal(): void
    {
        $original = $this->confirmedFinding();
        $page = $this->page('Current services.', self::QUOTE);
        $assessment = app(AccuracyAssessments::class)->page($this->owner, $original, $page, (string) Str::uuid());
        app(AccuracyAssessments::class)->dispatch($this->project, $assessment);
        $finding = AiAccuracyFinding::query()->where('assessment_id', $assessment->id)->sole();
        app(AccuracyAssessments::class)->review($this->owner, $finding, ['decision' => 'confirmed', 'reason' => 'I confirmed the incorrect footer.']);
        $action = app(CorrectionActions::class)->ownedPage($this->owner, $finding);
        $this->assertSame('owned_page_assisted', $action->kind);
        $this->assertStringContainsString(self::QUOTE, $action->draft);
        $this->assertStringContainsString($this->fact->statement, $action->draft);
        $this->assertSame(0, PageOpportunity::query()->count());
        $this->assertSame(0, PagePublication::query()->count());
        $this->assertSame($action->id, app(CorrectionActions::class)->ownedPage($this->owner, $finding)->id);
    }

    #[Test]
    public function changed_locale_and_old_page_captures_are_honestly_unavailable_for_correction(): void
    {
        [, $finding, $page] = $this->pageFinding();
        $page->update(['locale' => 'pt']);
        $report = app(AccuracyReport::class)->answer($this->answer);
        $this->assertFalse($report['assessments'][0]['source_current']);
        $page->update(['locale' => 'en']);
        $this->travel(31)->days();
        $this->assertFalse(app(AccuracyReport::class)->answer($this->answer)['assessments'][0]['source_current']);
    }

    #[Test]
    public function queued_checks_stop_after_owner_removal_and_do_not_buy_a_model_response(): void
    {
        config()->set('queue.default', 'database');
        $assessment = app(AccuracyAssessments::class)->answer($this->owner, $this->answer, (string) Str::uuid());
        $this->owner->projects()->detach($this->project);
        config()->set('queue.default', 'sync');
        app(PipelineRunner::class)->start('ai_accuracy', $this->project, ['assessment_id' => $assessment->id]);
        $this->assertSame('unavailable', $assessment->refresh()->status);
        $this->assertCount(0, $this->checker->sent());
    }

    private function assess(): AiAccuracyAssessment
    {
        $assessment = app(AccuracyAssessments::class)->answer($this->owner, $this->answer, (string) Str::uuid());
        app(AccuracyAssessments::class)->dispatch($this->project, $assessment);

        return $assessment->refresh();
    }

    private function confirmedFinding(): AiAccuracyFinding
    {
        $assessment = $this->assess();
        $finding = AiAccuracyFinding::query()->where('assessment_id', $assessment->id)->sole();
        app(AccuracyAssessments::class)->review($this->owner, $finding, ['decision' => 'confirmed', 'reason' => 'I checked this business assertion.']);

        return $finding;
    }

    private function page(string $body, string $footer = ''): SitePage
    {
        Http::fake(['example.com/*' => Http::response('<html><head><title>Services</title><link rel="canonical" href="https://example.com/services"></head><body><main><p>'.$body.'</p></main><footer>'.$footer.'</footer></body></html>')]);

        return SitePage::factory()->create(['url' => 'https://example.com/services', 'canonical_url' => 'https://example.com/services', 'canonical_hash' => hash('sha256', 'https://example.com/services'), 'locale' => 'en', 'tracked_at' => now(), 'page_kind' => 'commercial']);
    }

    /** @return array{AiAccuracyFinding, AiAccuracyFinding, SitePage} */
    private function pageFinding(): array
    {
        $original = $this->confirmedFinding();
        $page = $this->page(self::QUOTE);
        $assessment = app(AccuracyAssessments::class)->page($this->owner, $original, $page, (string) Str::uuid());
        app(AccuracyAssessments::class)->dispatch($this->project, $assessment);

        return [$original, AiAccuracyFinding::query()->where('assessment_id', $assessment->id)->sole(), $page];
    }
}
