<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Entitlements;
use App\Billing\FakeBillingProvider;
use App\Billing\PlanCatalog;
use App\Billing\PlanPrice;
use App\Models\BusinessFact;
use App\Models\PageOpportunity;
use App\Models\PageProposal;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\SitePage;
use App\Models\User;
use App\Pages\BusinessFacts;
use App\Pages\TrackedPages;
use App\Proposals\PageBlocks;
use App\Proposals\Proposals;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

final class FocusedOfferTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private SitePage $page;

    private BusinessFact $fact;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.version' => 3, 'billing.default_plan' => 'local-search']);
        $this->project = Project::factory()->create(['website_url' => 'https://example.com']);
        app(CurrentProject::class)->set($this->project);
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['plan' => 'local-search', 'plan_version' => 2, 'limit_overrides' => json_encode(['page_improvements' => 1])]);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $this->actingAs($this->owner);
        config(['queue.default' => 'sync']);
        $this->models = new FakeModelGateway;
        $this->app->instance(ModelGateway::class, $this->models);
        Http::fake(fn (Request $request) => Http::response('<html lang="en"><head><title>Cleaning service</title><link rel="canonical" href="'.$request->url().'"></head><body><main><p>Our cleaning service.</p></main></body></html>', 200, ['Content-Type' => 'text/html']));
        $this->page = app(TrackedPages::class)->track($this->project, 'https://example.com/service', 'en', 'commercial');
        $this->fact = app(BusinessFacts::class)->save($this->project, $this->owner, ['name' => 'Scope', 'statement' => 'Cleaning includes floors and bathrooms.', 'source_url' => 'https://example.com/service', 'source_note' => 'Owner checked the service scope.', 'status' => 'confirmed', 'confirm' => true, 'review_due_at' => now()->addDays(90)->toDateString()]);
    }

    public function test_first_acceptance_uses_one_unit_and_revisions_or_retries_never_use_another(): void
    {
        $proposal = $this->proposal();
        $this->assertSame(0, DB::table('page_improvement_allowances')->count());
        $this->accept($proposal);
        $this->accept($proposal->fresh());
        $this->assertSame(1, DB::table('page_improvement_allowances')->sum('units'));
        $this->assertSame(1, DB::table('project_usage_periods')->where('metric', 'page_improvements')->sum('used'));
        $input = $this->proposalPatch();
        $input['changes'][0]['after'] = 'The cleaning scope includes floors and bathrooms.';
        $revised = app(Proposals::class)->revise($proposal->fresh(), $this->owner, $proposal->current_revision_id, $input, 'Clarify the wording.', 15);
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['period_started_at' => now()->addSecond()]);
        $this->accept($revised);
        $this->assertSame(1, DB::table('page_improvement_allowances')->count());
        $this->assertSame(1, DB::table('project_usage_periods')->where('metric', 'page_improvements')->sum('used'));
        $this->assertSame(0, $proposal->publications()->count());
    }

    public function test_quota_refusal_rolls_back_approval_and_keeps_the_reviewable_draft(): void
    {
        $this->accept($this->proposal());
        $second = $this->proposal();
        $this->post('/proposals/'.$second->id.'/accept', ['revision_id' => $second->current_revision_id, 'active_seconds' => 10])->assertRedirect()->assertSessionHasErrors('approval');
        $this->assertNull($second->fresh()->approved_revision_id);
        $this->assertSame('review_required', $second->fresh()->status);
        $this->assertSame(1, DB::table('page_improvement_allowances')->count());
        $this->assertSame(0, $second->reviews()->where('action', 'accept')->count());
    }

    public function test_dismissed_drafts_and_no_change_decisions_use_no_allowance(): void
    {
        $proposal = $this->proposal();
        app(Proposals::class)->dismiss($proposal, $this->owner, $proposal->current_revision_id, 'This is not useful.', 10);
        $noChange = $this->proposal(['changes' => [], 'missing_facts' => [], 'no_change_reason' => 'The existing page already answers the question.']);
        $this->post('/proposals/'.$noChange->id.'/accept', ['revision_id' => $noChange->current_revision_id, 'active_seconds' => 10])->assertSessionHasErrors('approval');
        $this->assertSame(0, DB::table('page_improvement_allowances')->sum('units'));
    }

    public function test_a_first_acceptance_cannot_be_grandfathered_by_removing_the_subscription(): void
    {
        $proposal = $this->proposal();
        ProjectSubscription::query()->where('project_id', $this->project->id)->delete();
        $this->post('/proposals/'.$proposal->id.'/accept', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 10])->assertSessionHasErrors('approval');
        $this->assertNull($proposal->fresh()->approved_revision_id);
        $this->assertSame(0, DB::table('page_improvement_allowances')->count());
    }

    public function test_public_offer_is_usd_and_a_legacy_subscription_retains_its_original_allowances(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('pricing.plans.0.key', 'local-search')->where('pricing.plans.0.currency', 'usd')->where('pricing.plans.0.price_cents', 8900)->where('pricing.plans.0.limits.improvements', 4));
        $legacy = app(PlanCatalog::class)->get('medium', 1);
        $this->assertSame(9900, $legacy->priceCents);
        $this->assertSame('eur', $legacy->currency);
        $this->assertSame(30, $legacy->limit('articles'));
        $this->assertSame(0, app(PlanCatalog::class)->get('local-search', 2)->limit('articles'));
        $this->assertSame(1, app(PlanCatalog::class)->trial(2)->limit('page_improvements'));
    }

    public function test_checkout_keeps_the_offer_version_and_refuses_a_stale_offer(): void
    {
        $provider = new FakeBillingProvider;
        $this->app->instance(BillingProvider::class, $provider);
        $this->withHeaders(['X-Inertia' => 'true'])->post('/billing/checkout', ['plan' => 'local-search', 'plan_version' => 3])->assertStatus(409);
        $this->assertCount(1, $provider->checkouts);
        $this->post('/billing/checkout', ['plan' => 'local-search', 'plan_version' => 2])->assertSessionHasErrors('plan');
        $this->assertCount(1, $provider->checkouts);
    }

    public function test_page_limit_rejects_new_tracking_but_allows_a_fresh_capture_of_an_existing_page(): void
    {
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['limit_overrides' => json_encode(['tracked_pages' => 1])]);
        app(Entitlements::class)->forget($this->project);
        $this->post('/pages', ['url' => 'https://example.com/second', 'locale' => 'en', 'kind' => 'commercial'])->assertSessionHasErrors();
        $this->assertSame(1, SitePage::query()->tracked()->count());
        $captured = app(TrackedPages::class)->capture($this->project, $this->page);
        $this->assertNotNull($captured->latestSnapshot);
    }

    public function test_checkout_price_must_match_currency_amount_and_monthly_interval(): void
    {
        config(['billing.plans.2.local-search.stripe_price' => 'price_focus']);
        $plan = app(PlanCatalog::class)->get('local-search', 2);
        $price = ['id' => 'price_focus', 'active' => true, 'currency' => 'usd', 'unit_amount' => 8900, 'type' => 'recurring', 'recurring' => ['interval' => 'month', 'interval_count' => 1]];
        PlanPrice::verify($plan, $price);
        foreach ([['currency' => 'eur'], ['unit_amount' => 9900], ['recurring' => ['interval' => 'year', 'interval_count' => 1]]] as $change) {
            try {
                PlanPrice::verify($plan, array_replace($price, $change));
                $this->fail('A mismatched provider price must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('does not match', $exception->getMessage());
            }
        }
    }

    /** @param array<string, mixed>|null $input */
    private function proposal(?array $input = null): PageProposal
    {
        $snapshot = $this->page->fresh()->latestSnapshot;
        $opportunity = PageOpportunity::query()->create(['site_page_id' => $this->page->id, 'kind' => 'missing_business_fact', 'diagnosed_issue' => 'Clarify scope.', 'suggested_scope' => 'One paragraph.', 'evidence_snapshot' => ['snapshot_id' => $snapshot->id, 'canonical_url' => $this->page->canonical_url, 'locale' => 'en'], 'confidence' => 'low', 'effort' => 'small', 'ranking_factors' => [], 'missing_fact_questions' => [], 'overlap_page_ids' => [], 'status' => 'open', 'fingerprint' => hash('sha256', (string) Str::uuid()), 'diagnosed_at' => now()]);
        $this->models->willAnswer([json_encode($input ?? $this->proposalPatch(), JSON_THROW_ON_ERROR)]);

        return app(Proposals::class)->begin($opportunity, $this->owner);
    }

    /** @return array<string, mixed> */
    private function proposalPatch(): array
    {
        $block = app(PageBlocks::class)->from($this->page->latestSnapshot)[0];

        return ['changes' => [['kind' => 'text_section', 'operation' => 'replace', 'locator' => $block['id'], 'before' => $block['text'], 'after' => 'Cleaning includes floors and bathrooms.', 'reason' => 'Clarify confirmed scope.', 'fact_version_ids' => [$this->fact->current_version_id], 'target_page_id' => null, 'anchor_text' => null]], 'missing_facts' => []];
    }

    private function accept(PageProposal $proposal): void
    {
        $this->post('/proposals/'.$proposal->id.'/accept', ['revision_id' => $proposal->current_revision_id, 'active_seconds' => 10])->assertRedirect()->assertSessionHasNoErrors();
    }
}
