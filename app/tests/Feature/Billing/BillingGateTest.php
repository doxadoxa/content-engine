<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Enums\OnboardingStatus;
use App\Enums\ProjectStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The gates, at the two places that matter.
 *
 * `engine:tick` is the important one: it is where unattended spend comes from,
 * and everything it starts flows from one list of projects. The routes are the
 * other half — the buttons somebody can press themselves.
 */
final class BillingGateTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->project = Project::factory()->unbilled()->create([
            'status' => ProjectStatus::Active,
            'onboarding_status' => OnboardingStatus::Active,
        ]);

        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function the_tick_skips_a_project_that_may_not_spend_and_says_why(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialExpired()->create();

        $this->console('engine:tick')
            ->assertSuccessful()
            ->expectsOutputToContain('skipped — The trial has ended');

        // Not one run started. This is the assertion the whole subsystem is
        // for: everything the engine does unattended flows from that list.
        $this->assertSame(0, PipelineRun::acrossProjects()->count());
    }

    #[Test]
    public function the_tick_works_for_a_paying_project(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->create();

        $this->console('engine:tick')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('skipped');
    }

    #[Test]
    public function the_tick_starts_nothing_for_a_project_nobody_ever_put_on_a_plan(): void
    {
        // Fails closed. A project with no subscription row at all — the state
        // every existing project is in the moment this ships — must not be
        // treated as unlimited.
        $this->console('engine:tick')
            ->assertSuccessful()
            ->expectsOutputToContain('Add a card to start the engine');

        $this->assertSame(0, PipelineRun::acrossProjects()->count());
    }

    #[Test]
    public function a_spending_route_is_refused_where_a_person_can_see_it(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialExpired()->create();

        $this->actingAs($this->owner)
            ->from(route('content.index'))
            ->post(route('content.articles.store'), ['topic' => 'wooden doors'])
            ->assertRedirect();

        // Asserted on the next render rather than on a session key, because a
        // session key is what the first version of this used and nothing read
        // it: the operator pressed the button, the page came back unchanged,
        // and the reason went somewhere no screen looks. The toast hook listens
        // for Inertia's own flash event, so this is the only assertion that
        // proves anybody was told.
        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertOk()
            ->assertSee('trial has ended', escape: false);
    }

    #[Test]
    public function a_route_is_refused_by_the_quota_it_names(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('small')->create();
        app(Entitlements::class)->record($this->project, Metric::Articles, 10);

        $this->actingAs($this->owner)
            ->from(route('content.index'))
            ->post(route('content.articles.store'), ['topic' => 'wooden doors'])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertOk()
            ->assertSee('articles are used up', escape: false);
    }

    #[Test]
    public function a_used_up_quota_is_visible_before_anybody_presses_anything(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('small')->create();
        app(Entitlements::class)->record($this->project, Metric::Articles, 10);

        // Running out of articles is not a global refusal — the engine keeps
        // cutting social posts — so `may_generate` stays true. Which is exactly
        // why it needs its own prop: without one, the only surface saying
        // anything was a progress bar on a page nobody had a reason to open.
        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('billing.may_generate', true)
                ->where('billing.refusal', null)
                ->where('billing.exhausted', ['articles'])
            );
    }

    #[Test]
    public function a_route_gated_on_one_quota_is_not_stopped_by_another(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->plan('small')->create();
        app(Entitlements::class)->record($this->project, Metric::Articles, 10);

        // Out of articles, not out of audits. A counter filling must not read
        // as a pause.
        $this->actingAs($this->owner)
            ->post(route('audit.recheck'))
            ->assertRedirect()
            ->assertSessionMissing('errors');
    }

    #[Test]
    public function reading_is_never_gated(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->canceled()->create();

        // Everything a project made stays readable for ever. The plan bounds
        // what gets made; it does not repossess the work.
        foreach (['content.index', 'approvals.index', 'calendar.index', 'audit.index'] as $route) {
            $this->actingAs($this->owner)->get(route($route))->assertOk();
        }
    }

    #[Test]
    public function the_plan_screen_is_never_behind_the_gate_it_explains(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialExpired()->create();

        $this->actingAs($this->owner)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('billing/index')
                ->where('entitlement.refusal.code', 'trial_ended')
                ->has('plans', 2)
            );
    }

    #[Test]
    public function every_page_carries_what_the_project_may_do(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialing()->create();

        // Shared rather than per-page, because the banner it drives belongs to
        // the frame — and a prop somebody forgot to pass would be missing from
        // exactly the screens with the buttons on them.
        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('billing.status', 'trialing')
                ->where('billing.may_generate', true)
                ->has('billing.trial_ends_at')
            );
    }

    #[Test]
    public function the_sweep_ends_what_has_run_out_and_pauses_the_project(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialExpired()->create();

        $this->console('billing:sweep')->assertSuccessful();

        $this->assertSame(ProjectStatus::Paused, $this->project->fresh()?->status);
        $this->assertSame('canceled', ProjectSubscription::query()->sole()->status->value);
    }

    #[Test]
    public function the_sweep_leaves_a_trial_that_still_has_time_alone(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->trialing()->create();

        $this->console('billing:sweep')
            ->assertSuccessful()
            ->expectsOutputToContain('Nothing has run out');

        $this->assertSame(ProjectStatus::Active, $this->project->fresh()?->status);
    }

    #[Test]
    public function the_sweep_is_safe_to_run_twice(): void
    {
        ProjectSubscription::factory()->forProject($this->project)->pastDue()->create([
            'grace_ends_at' => now()->subDay(),
        ]);

        $this->console('billing:sweep')->assertSuccessful();
        $this->console('billing:sweep')
            ->assertSuccessful()
            ->expectsOutputToContain('Nothing has run out');
    }

    #[Test]
    public function assigning_a_plan_from_the_terminal_starts_the_engine_again(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Paused])->save();
        ProjectSubscription::factory()->forProject($this->project)->canceled()->create();

        $this->console('billing:assign', [
            'project' => $this->project->slug,
            'plan' => 'medium',
            '--resume' => true,
        ])->assertSuccessful();

        $this->assertSame(ProjectStatus::Active, $this->project->fresh()?->status);
        $this->assertTrue(app(Entitlements::class)->for($this->project)->mayGenerate());
    }

    #[Test]
    public function assigning_an_unknown_plan_fails_rather_than_guessing(): void
    {
        $this->console('billing:assign', [
            'project' => $this->project->slug,
            'plan' => 'platinum',
        ])->assertFailed();

        $this->assertSame(0, ProjectSubscription::query()->count());
    }

    /**
     * `artisan()` is declared as returning `PendingCommand|int`, so without
     * this every call site would repeat the same annotation to chain
     * assertions onto it.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function console(string $command, array $arguments = []): PendingCommand
    {
        /** @var PendingCommand $pending */
        $pending = $this->artisan($command, $arguments);

        return $pending;
    }
}
