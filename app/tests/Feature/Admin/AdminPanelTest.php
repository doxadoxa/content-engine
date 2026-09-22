<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Entitlements;
use App\Billing\FakeBillingProvider;
use App\Billing\Metric;
use App\Enums\BillingStatus;
use App\Enums\ProjectStatus;
use App\Models\AdminAction;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\ProviderSpendRecord;
use App\Models\User;
use App\Pipelines\Steps\AiAccuracy\CheckAccuracy;
use App\Support\Metering\ProjectSpend;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Running the service, as opposed to running a project.
 *
 * The assertions worth having here are the ones about the boundary. Everything
 * behind `/admin` reads across tenants, which is the single thing the rest of
 * this application is built to make impossible — so who may open it, and
 * whether it can be reached by guessing, matter more than any table on it.
 */
final class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.version' => 1, 'billing.default_plan' => 'medium']);

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::factory()->create(['name' => 'Cleaning Point']);
    }

    #[Test]
    public function an_ordinary_operator_cannot_find_the_panel(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'owner']);

        // 404 rather than 403: a 403 confirms `/admin` is a real address on
        // this deployment, and there is nothing an ordinary customer gains by
        // knowing that.
        foreach ([
            '/admin',
            '/admin/users',
            '/admin/projects',
            '/admin/subscriptions',
            "/admin/projects/{$this->project->getKey()}",
        ] as $path) {
            $this->actingAs($operator)->get($path)->assertNotFound();
        }
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in_rather_than_told_it_exists(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    #[Test]
    public function an_administrator_with_an_unproved_address_is_not_let_in_either(): void
    {
        $unverified = User::factory()->unverified()->create(['is_admin' => true]);

        $this->actingAs($unverified)->get('/admin')->assertRedirect('/email/verify');
    }

    #[Test]
    public function the_operator_may_not_change_a_plan_by_posting_at_it(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'owner']);

        $this->actingAs($operator)
            ->post("/admin/projects/{$this->project->getKey()}/plan", ['plan' => 'enterprise'])
            ->assertNotFound();

        $this->assertNotSame('enterprise', ProjectSubscription::query()->sole()->plan);
    }

    #[Test]
    public function the_overview_reads_every_tenant_rather_than_the_current_one(): void
    {
        // The one place in this application where crossing tenants is the
        // point. `ProjectScope` fails closed, so a query somebody forgot to
        // widen shows an empty table — a visible bug rather than a leak.
        Project::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/overview')
                ->where('counts.projects', 3)
            );
    }

    #[Test]
    public function the_overview_puts_what_a_project_costs_beside_what_it_pays(): void
    {
        // The figure no payment provider can compute for us: it needs both
        // halves, and only this application knows the second one.
        $run = PipelineRun::factory()->for($this->project)->create();
        PipelineStep::factory()->for($run, 'pipelineRun')->create(['cost_micros' => 3_000_000]);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Medium, from the project factory's subscription.
                ->where('revenue_by_currency.0.currency', 'eur')
                ->where('revenue_by_currency.0.cents', 9_900)
                ->where('contribution_micros', null)
                ->where('cost_currency', 'usd')
                ->where('cost_micros', 3_000_000)
                ->where('margins.0.name', 'Cleaning Point')
            );
    }

    #[Test]
    public function mixed_plan_versions_keep_revenue_currencies_and_never_invent_an_exchange_rate(): void
    {
        config(['billing.version' => 2, 'billing.default_plan' => 'local-search']);
        $dollar = Project::factory()->create(['name' => 'Dollar project']);
        ProjectSubscription::query()->where('project_id', $dollar->id)->update(['plan' => 'local-search', 'plan_version' => 2]);
        foreach ([[$this->project, 3_000_000], [$dollar, 1_000_000]] as [$project, $cost]) {
            $run = PipelineRun::factory()->for($project)->create();
            PipelineStep::factory()->for($run, 'pipelineRun')->create(['cost_micros' => $cost]);
        }
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('revenue_by_currency', [['currency' => 'eur', 'cents' => 9_900], ['currency' => 'usd', 'cents' => 8_900]])
            ->where('cost_currency', 'usd')->where('cost_micros', 4_000_000)->where('contribution_micros', null)
            ->where('margins.0.currency', 'eur')->where('margins.0.contribution_micros', null)
            ->where('margins.1.currency', 'usd')->where('margins.1.contribution_micros', 88_000_000));
        $this->get('/admin/projects')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projects.data.0.currency', 'eur')->where('projects.data.1.currency', 'usd')->where('cost_currency', 'usd'));
        $this->get('/admin/subscriptions')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subscriptions.data', function ($rows) use ($dollar): bool {
                /** @var list<array<string,mixed>> $rows */
                $byProject = collect($rows)->keyBy('project_id');

                return $byProject->get($this->project->id)['currency'] === 'eur' && $byProject->get($dollar->id)['currency'] === 'usd';
            }));
        $this->get('/admin/projects/'.$this->project->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entitlement.plan.currency', 'eur')->where('monthly_plan_fee_cents', 9_900)->where('cost_currency', 'usd')->where('contribution_micros', null)
            ->where('plans.1.currency', 'usd'));
        $this->get('/admin/projects/'.$dollar->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entitlement.plan.currency', 'usd')->where('monthly_plan_fee_cents', 8_900)->where('contribution_micros', 88_000_000));
        $legacy = ProjectSubscription::query()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame(1, $legacy->plan_version);
        $this->assertSame(9_900, $legacy->plan()->priceCents);
    }

    #[Test]
    public function dollar_only_monthly_fees_have_a_known_usage_contribution_without_treating_trial_price_as_receipts(): void
    {
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['plan' => 'local-search', 'plan_version' => 2]);
        $run = PipelineRun::factory()->for($this->project)->create();
        PipelineStep::factory()->for($run, 'pipelineRun')->create(['cost_micros' => 3_000_000]);
        $this->actingAs($this->admin)->get('/admin')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('revenue_by_currency', [['currency' => 'usd', 'cents' => 8_900]])->where('contribution_micros', 86_000_000));
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['status' => 'trialing', 'trial_ends_at' => now()->addDays(7)]);
        $this->get('/admin')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('revenue_by_currency', [])->where('margins.0.price_cents', 0)->where('contribution_micros', -3_000_000));
        $this->get('/admin/projects/'.$this->project->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('monthly_plan_fee_cents', 0)->where('contribution_micros', -3_000_000));
    }

    #[Test]
    public function unknown_provider_charges_make_dollar_contributions_unavailable_everywhere(): void
    {
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['plan' => 'local-search', 'plan_version' => 2]);
        app(CurrentProject::class)->run($this->project, function (): void {
            $run = PipelineRun::factory()->create(['pipeline' => 'ai_accuracy', 'input' => ['assessment_id' => (string) Str::ulid()]]);
            PipelineStep::factory()->for($run, 'pipelineRun')->create(['step_key' => CheckAccuracy::key(), 'cost_micros' => 1_000_000]);
            ProviderSpendRecord::query()->create(['pipeline_run_id' => $run->id, 'step_key' => CheckAccuracy::key(), 'status' => 'pending', 'provider' => 'synthetic', 'model' => 'synthetic-unpriced', 'role' => 'fact_check', 'price_list_version' => 1, 'request_hash' => hash('sha256', 'pending')]);
        });
        $this->actingAs($this->admin)->get('/admin')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cost_micros', 1_000_000)->where('cost_complete', false)->where('contribution_micros', null)
            ->where('margins.0.currency', 'usd')->where('margins.0.cost_complete', false)->where('margins.0.contribution_micros', null));
        $this->get('/admin/projects')->assertInertia(fn (AssertableInertia $page) => $page->where('projects.data.0.cost_complete', false));
        $this->get('/admin/projects/'.$this->project->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('spend.unknown_provider_attempts', 1)->where('spend.completeness', 'incomplete')->where('contribution_micros', null));
        $summary = ProjectSpend::summaries([$this->project->id], now()->startOfMonth())[$this->project->id];
        $this->assertSame(ProjectSpend::for($this->project, now()->startOfMonth())->toArray(), $summary);
    }

    #[Test]
    public function the_overview_reads_spend_for_every_tenant_in_a_bounded_number_of_queries(): void
    {
        foreach (Project::factory()->count(5)->create() as $other) {
            $run = PipelineRun::factory()->for($other)->create();
            PipelineStep::factory()->for($run, 'pipelineRun')->create(['cost_micros' => 1_000]);
        }

        // Two queries for spend however many tenants there are, rather than
        // two per tenant: fine at three projects and a page load at three
        // hundred.
        DB::enableQueryLog();

        $this->actingAs($this->admin)->get('/admin')->assertOk();

        $spendQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains((string) $entry['query'], 'sum(cost_micros)'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(2, $spendQueries);
    }

    #[Test]
    public function a_project_detail_reads_the_entitlement_as_that_project(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            app(Entitlements::class)->record($this->project, Metric::Articles, 4);
        });

        // The controller stands outside every tenant, so the counters have to
        // be read from inside the one being looked at or they read as zero.
        $this->actingAs($this->admin)
            ->get("/admin/projects/{$this->project->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/project')
                ->where('entitlement.usage.articles.used', 4)
                ->where('entitlement.usage.articles.limit', 30)
            );
    }

    #[Test]
    public function assigning_a_plan_writes_down_who_did_it_and_what_changed(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/plan", ['plan' => 'small'])
            ->assertRedirect();

        $this->assertSame('small', ProjectSubscription::query()->sole()->plan);

        // Six months from now, "why is this account on Enterprise" has to have
        // an answer that is not a guess.
        $action = AdminAction::query()->sole();

        $this->assertSame('plan.assigned', $action->action);
        $this->assertSame($this->admin->getKey(), $action->user_id);
        $this->assertSame('medium', $action->before['plan']);
        $this->assertSame('small', $action->after['plan']);
    }

    #[Test]
    public function an_unknown_plan_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/plan", ['plan' => 'platinum'])
            ->assertStatus(422);

        $this->assertSame(0, AdminAction::query()->count());
    }

    #[Test]
    public function a_bespoke_arrangement_carries_its_own_numbers(): void
    {
        $this->actingAs($this->admin)->post("/admin/projects/{$this->project->getKey()}/plan", [
            'plan' => 'enterprise',
            'overrides' => ['articles' => 400],
        ])->assertRedirect();

        $entitlement = app(CurrentProject::class)->run(
            $this->project,
            fn () => app(Entitlements::class)->for($this->project),
        );

        $this->assertSame(400, $entitlement->limit('articles'));
    }

    #[Test]
    public function extending_a_lapsed_trial_gives_days_that_can_be_used(): void
    {
        ProjectSubscription::query()->sole()->update([
            'plan' => 'trial',
            'status' => BillingStatus::Trialing,
            'trial_ends_at' => now()->subWeek(),
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/trial", ['days' => 5])
            ->assertRedirect();

        $subscription = ProjectSubscription::query()->sole();

        // From today rather than from the date it lapsed, which would
        // back-date five days into a window that has already closed.
        $this->assertTrue($subscription->trial_ends_at->isFuture());
        $this->assertTrue($subscription->trial_ends_at->isSameDay(now()->addDays(5)));
    }

    #[Test]
    public function extending_a_live_trial_adds_to_the_end_rather_than_to_today(): void
    {
        ProjectSubscription::query()->sole()->update([
            'plan' => 'trial',
            'status' => BillingStatus::Trialing,
            'trial_ends_at' => now()->addDays(2),
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/trial", ['days' => 5])
            ->assertRedirect();

        $this->assertTrue(
            ProjectSubscription::query()->sole()->trial_ends_at->isSameDay(now()->addDays(7)),
        );
    }

    #[Test]
    public function a_provider_backed_plan_change_goes_through_stripe(): void
    {
        $provider = app(BillingProvider::class);
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);
        $provider->canChangePlan = true;

        $payer = User::factory()->create();
        ProjectSubscription::query()->sole()->update([
            'stripe_id' => 'sub_test',
            'billing_user_id' => $payer->getKey(),
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/plan", ['plan' => 'small'])
            ->assertRedirect();

        // Changing only the local row would leave the customer paying one tier
        // and receiving another — and the next `customer.subscription.updated`
        // would read the unchanged metadata and put the entitlement back.
        $this->assertSame('small', $provider->planChanges[0]['plan']);
        $this->assertSame('small', ProjectSubscription::query()->sole()->plan);
    }

    #[Test]
    public function a_plan_change_stripe_refuses_changes_nothing_here(): void
    {
        $provider = app(BillingProvider::class);
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);
        $provider->canChangePlan = false;

        $payer = User::factory()->create();
        ProjectSubscription::query()->sole()->update([
            'stripe_id' => 'sub_test',
            'billing_user_id' => $payer->getKey(),
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/plan", ['plan' => 'small'])
            ->assertSessionHasErrors('plan');

        // A local row that disagrees with what is being charged is worse than
        // a button that says it could not do the thing.
        $this->assertSame('medium', ProjectSubscription::query()->sole()->plan);
    }

    #[Test]
    public function a_provider_backed_trial_is_extended_at_stripe_too(): void
    {
        $provider = app(BillingProvider::class);
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);
        $provider->canChangePlan = true;

        $payer = User::factory()->create();
        ProjectSubscription::query()->sole()->update([
            'plan' => 'trial',
            'status' => BillingStatus::Trialing,
            'trial_ends_at' => now()->addDay(),
            'stripe_id' => 'sub_test',
            'billing_user_id' => $payer->getKey(),
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/trial", ['days' => 5])
            ->assertRedirect();

        // Stripe owns the date it invoices on. Moving only our copy gives
        // somebody a free window we believe in and Stripe does not — charged
        // during the days we just promised them.
        $this->assertCount(1, $provider->trialExtensions);
        $this->assertSame($this->project->getKey(), $provider->trialExtensions[0]['project']);
    }

    #[Test]
    public function a_trial_extension_stripe_refuses_changes_nothing_here(): void
    {
        $provider = app(BillingProvider::class);
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);
        $provider->canChangePlan = false;

        $ends = now()->addDay();

        ProjectSubscription::query()->sole()->update([
            'plan' => 'trial',
            'status' => BillingStatus::Trialing,
            'trial_ends_at' => $ends,
            'stripe_id' => 'sub_test',
            'billing_user_id' => User::factory()->create()->getKey(),
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/trial", ['days' => 5])
            ->assertSessionHasErrors('days');

        $this->assertTrue(ProjectSubscription::query()->sole()->trial_ends_at?->isSameDay($ends));
    }

    #[Test]
    public function a_comped_trial_with_no_provider_is_still_extended_locally(): void
    {
        ProjectSubscription::query()->sole()->update([
            'plan' => 'trial',
            'status' => BillingStatus::Trialing,
            'trial_ends_at' => now()->subWeek(),
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/trial", ['days' => 5])
            ->assertRedirect();

        $this->assertTrue(ProjectSubscription::query()->sole()->trial_ends_at->isSameDay(now()->addDays(5)));
    }

    #[Test]
    public function the_engine_can_be_stopped_and_started_again(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/status", ['status' => 'paused'])
            ->assertRedirect();

        $this->assertSame(ProjectStatus::Paused, $this->project->fresh()?->status);

        $this->actingAs($this->admin)
            ->post("/admin/projects/{$this->project->getKey()}/status", ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame(ProjectStatus::Active, $this->project->fresh()?->status);
        $this->assertSame(2, AdminAction::query()->where('action', 'project.status')->count());
    }

    #[Test]
    public function a_row_that_disagrees_with_stripe_is_flagged_before_the_reconciler_gets_to_it(): void
    {
        ProjectSubscription::query()->sole()->update([
            'status' => BillingStatus::Active,
            'stripe_id' => 'sub_test',
            'stripe_status' => 'past_due',
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/subscriptions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/subscriptions')
                ->where('subscriptions.data.0.disagrees', true)
            );
    }

    #[Test]
    public function every_screen_the_panel_navigates_to_opens_for_an_administrator(): void
    {
        // `AdminTabs` sends an administrator to these four addresses, so all
        // four have to answer. That is the whole of what this asserts: the tab
        // row itself is React, and nothing in this suite renders React — there
        // is no SSR and no JavaScript test runner, so deleting the tabs from a
        // page leaves every test here passing. The route side is held by the
        // type checker instead, because the tabs' hrefs come from the
        // Wayfinder helpers generated from `routes/admin.php`.
        //
        // `/admin/users` had no render coverage at all before this.
        foreach ([
            '/admin' => 'admin/overview',
            '/admin/projects' => 'admin/projects',
            '/admin/users' => 'admin/users',
            '/admin/subscriptions' => 'admin/subscriptions',
        ] as $path => $component) {
            $this->actingAs($this->admin)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component($component));
        }
    }

    #[Test]
    public function the_panel_opens_on_a_deployment_that_has_no_projects_at_all(): void
    {
        // The state production is actually in, which nothing else here covers
        // — every other test in this file runs with a project and a
        // subscription already made in `setUp`. What it proves is that the
        // panel answers rather than divides by zero somewhere on the way. The
        // empty-state rows written for this case are React and are not
        // rendered here; `margins` being empty is the prop they key off.
        ProjectSubscription::query()->delete();
        Project::query()->delete();

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/overview')
                ->where('counts.projects', 0)
                ->where('margins', [])
            );

        $this->actingAs($this->admin)
            ->get('/admin/projects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/projects')
                ->where('projects.data', [])
            );
    }

    #[Test]
    public function horizon_is_opened_by_the_same_flag_as_the_panel(): void
    {
        // The email allow-list was a bootstrap mechanism, never a permission
        // model: it cannot be revoked without a deploy and records nothing
        // about who is on it.
        $this->assertTrue(Gate::forUser($this->admin)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('viewHorizon'));
    }
}
