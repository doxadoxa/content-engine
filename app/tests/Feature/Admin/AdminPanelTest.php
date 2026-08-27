<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Enums\BillingStatus;
use App\Enums\ProjectStatus;
use App\Models\AdminAction;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
                ->where('revenue_cents', 9_900)
                ->where('cost_micros', 3_000_000)
                ->where('margins.0.name', 'Cleaning Point')
            );
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
    public function horizon_is_opened_by_the_same_flag_as_the_panel(): void
    {
        // The email allow-list was a bootstrap mechanism, never a permission
        // model: it cannot be revoked without a deploy and records nothing
        // about who is on it.
        $this->assertTrue(Gate::forUser($this->admin)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('viewHorizon'));
    }
}
