<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Billing\Contracts\BillingProvider;
use App\Billing\FakeBillingProvider;
use App\Billing\StripeWebhook;
use App\Billing\Subscriptions;
use App\Billing\TrialEligibility;
use App\Enums\BillingStatus;
use App\Enums\OnboardingStatus;
use App\Enums\ProjectStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Archiving: the owner's way to be done with a project without deleting it.
 *
 * The assertions that matter are the ones about money and about what stays
 * behind — the subscription stops at Stripe, nothing restarts the engine, and
 * the row still counts for the once-per-site free sample.
 */
final class ArchiveProjectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owner_archives_a_project_and_its_stripe_subscription_ends(): void
    {
        [$owner, $project] = $this->ownerWithProject('Old test site');
        ProjectSubscription::query()->where('project_id', $project->getKey())->update([
            'stripe_id' => 'sub_test',
            'billing_user_id' => $owner->getKey(),
        ]);

        $this->actingAs($owner)
            ->withSession([ProjectManager::SESSION_KEY => $project->getKey()])
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Old test site'])
            ->assertRedirect('/onboarding')
            ->assertSessionMissing(ProjectManager::SESSION_KEY);

        $project->refresh();
        $this->assertNotNull($project->archived_at);
        $this->assertSame(ProjectStatus::Paused, $project->status);

        $subscription = ProjectSubscription::query()->where('project_id', $project->getKey())->sole();
        $this->assertSame(BillingStatus::Canceled, $subscription->status);
        $this->assertNotNull($subscription->canceled_at);
        $this->assertSame([$project->getKey()], $this->provider()->canceledSubscriptions);
    }

    #[Test]
    public function a_subscription_with_nothing_at_stripe_is_only_cancelled_here(): void
    {
        [$owner, $project] = $this->ownerWithProject('Sample');
        $other = Project::factory()->create();
        $owner->projects()->attach($other, ['role' => 'owner']);

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Sample'])
            ->assertRedirect('/projects');

        $this->assertSame(
            BillingStatus::Canceled,
            ProjectSubscription::query()->where('project_id', $project->getKey())->sole()->status,
        );
        $this->assertSame([], $this->provider()->canceledSubscriptions);
    }

    #[Test]
    public function a_stripe_failure_leaves_the_project_as_it_was(): void
    {
        [$owner, $project] = $this->ownerWithProject('Still billed');
        ProjectSubscription::query()->where('project_id', $project->getKey())->update([
            'stripe_id' => 'sub_test',
            'billing_user_id' => $owner->getKey(),
        ]);
        $this->provider()->cancelFails = true;

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Still billed'])
            ->assertRedirect();

        // Archiving anyway would hide a project that is still being charged
        // for, with no screen left to stop it from.
        $this->assertNull($project->refresh()->archived_at);
        $this->assertSame(ProjectStatus::Active, $project->status);
        $this->assertSame(
            BillingStatus::Active,
            ProjectSubscription::query()->where('project_id', $project->getKey())->sole()->status,
        );
    }

    #[Test]
    public function the_wrong_name_is_refused(): void
    {
        [$owner, $project] = $this->ownerWithProject('Exact name');

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'exact name'])
            ->assertSessionHasErrors('confirmation');

        $this->assertNull($project->refresh()->archived_at);
    }

    #[Test]
    public function a_member_who_is_not_the_owner_cannot_archive(): void
    {
        [, $project] = $this->ownerWithProject('Shared');
        $operator = User::factory()->create();
        $operator->projects()->attach($project, ['role' => 'operator']);

        $this->actingAs($operator)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Shared'])
            ->assertForbidden();

        $this->assertNull($project->refresh()->archived_at);
    }

    #[Test]
    public function an_archived_project_is_gone_from_the_application(): void
    {
        [$owner, $project] = $this->ownerWithProject('Gone');
        $kept = Project::factory()->create(['name' => 'Kept']);
        $owner->projects()->attach($kept, ['role' => 'owner']);

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Gone'])
            ->assertRedirect('/projects');

        $this->actingAs($owner)->get('/projects')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('projects', 1)
                ->where('projects.0.id', $kept->getKey()),
        );

        $this->actingAs($owner)->post("/projects/{$project->getKey()}/switch")->assertForbidden();
        $this->actingAs($owner)->get("/projects/{$project->getKey()}/edit")->assertNotFound();

        // A session still pointing at it falls back to a live project.
        $this->actingAs($owner)
            ->withSession([ProjectManager::SESSION_KEY => $project->getKey()])
            ->get('/projects')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.project.id', $kept->getKey()));
    }

    /** Guards against archiving ever becoming a hard delete: the row is what the per-site rule reads. */
    #[Test]
    public function an_archived_site_still_counts_against_a_second_free_sample(): void
    {
        [$owner, $project] = $this->ownerWithProject('Sampled', ['website_url' => 'https://cleaningpoint.net']);
        ProjectSubscription::query()->where('project_id', $project->getKey())->update(['plan' => 'preview']);

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Sampled'])
            ->assertRedirect();

        $again = Project::factory()->onboarding()->unbilled()->create(['website_url' => 'https://www.cleaningpoint.net/']);
        $owner->projects()->attach($again, ['role' => 'owner']);

        $this->assertFalse(app(TrialEligibility::class)->mayHaveAPreview($owner, $again));
    }

    #[Test]
    public function a_subscription_stripe_reports_healthy_does_not_restart_an_archived_project(): void
    {
        [$owner, $project] = $this->ownerWithProject('Archived');
        $owner->forceFill(['stripe_id' => 'cus_test'])->save();

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Archived'])
            ->assertRedirect();

        // As though billing had paused it before the owner archived it.
        ProjectSubscription::query()->where('project_id', $project->getKey())->update(['paused_by_billing' => true]);

        app(StripeWebhook::class)->handle($this->subscriptionEvent($project, 'active'));

        $this->assertSame(ProjectStatus::Paused, $project->refresh()->status);
        $this->assertSame(
            BillingStatus::Canceled,
            ProjectSubscription::query()->where('project_id', $project->getKey())->sole()->status,
        );
    }

    #[Test]
    public function resume_reads_the_archive_fresh_rather_than_trusting_the_instance(): void
    {
        [$owner, $project] = $this->ownerWithProject('Archived');
        $stale = Project::query()->whereKey($project->getKey())->firstOrFail();

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Archived'])
            ->assertRedirect();
        ProjectSubscription::query()->where('project_id', $project->getKey())->update(['paused_by_billing' => true]);

        app(Subscriptions::class)->resume($stale);

        $this->assertSame(ProjectStatus::Paused, $project->refresh()->status);
    }

    #[Test]
    public function a_checkout_finished_after_archiving_is_canceled_and_starts_nothing(): void
    {
        Queue::fake();
        [$owner, $project] = $this->ownerWithProject('Launching', ['onboarding_status' => OnboardingStatus::Launching]);
        ProjectSubscription::query()->where('project_id', $project->getKey())->delete();
        $owner->forceFill(['stripe_id' => 'cus_test'])->save();

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Launching'])
            ->assertRedirect();

        // The checkout was opened before the archive and completed after it.
        app(StripeWebhook::class)->handle($this->subscriptionEvent($project, 'active', 'customer.subscription.created'));

        $subscription = ProjectSubscription::query()->where('project_id', $project->getKey())->sole();
        $this->assertSame('sub_test', $subscription->stripe_id);
        $this->assertSame(BillingStatus::Canceled, $subscription->status);
        $this->assertSame([$project->getKey()], $this->provider()->canceledSubscriptions);
        $this->assertSame(0, PipelineRun::acrossProjects()->where('project_id', $project->getKey())->count());
        $this->assertSame(ProjectStatus::Paused, $project->refresh()->status);
    }

    #[Test]
    public function an_administrator_cannot_start_an_archived_project_again(): void
    {
        [$owner, $project] = $this->ownerWithProject('Archived');

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Archived'])
            ->assertRedirect();

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post("/admin/projects/{$project->getKey()}/status", ['status' => 'active'])
            ->assertSessionHasErrors('status');

        $this->assertSame(ProjectStatus::Paused, $project->refresh()->status);
    }

    #[Test]
    public function an_administrator_cannot_extend_the_trial_of_an_archived_project(): void
    {
        [$owner, $project] = $this->ownerWithProject('Archived');

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Archived'])
            ->assertRedirect();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post("/admin/projects/{$project->getKey()}/trial", ['days' => 7])
            ->assertSessionHasErrors('days');

        $this->assertSame(ProjectStatus::Paused, $project->refresh()->status);
    }

    #[Test]
    public function the_settings_screen_renders_for_its_owner(): void
    {
        [$owner, $project] = $this->ownerWithProject('Mine');

        $this->actingAs($owner)->get("/projects/{$project->getKey()}/edit")->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('projects/edit')
                ->where('project.id', $project->getKey())
                ->where('project.name', 'Mine'),
        );
    }

    #[Test]
    public function archiving_twice_finds_nothing_the_second_time(): void
    {
        [$owner, $project] = $this->ownerWithProject('Once');
        $other = Project::factory()->create();
        $owner->projects()->attach($other, ['role' => 'owner']);

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Once'])
            ->assertRedirect('/projects');

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Once'])
            ->assertNotFound();
    }

    #[Test]
    public function a_draft_cannot_be_archived(): void
    {
        [$owner, $project] = $this->ownerWithProject('Unfinished', ['onboarding_status' => OnboardingStatus::Draft]);

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Unfinished'])
            ->assertNotFound();

        $this->assertNull($project->refresh()->archived_at);
    }

    #[Test]
    public function an_archived_sample_frees_its_account_for_a_sample_elsewhere(): void
    {
        [$owner, $project] = $this->ownerWithProject('Wrong address', ['website_url' => 'https://wrong.example']);
        ProjectSubscription::query()->where('project_id', $project->getKey())->update(['plan' => 'preview']);

        $right = Project::factory()->onboarding()->unbilled()->create(['website_url' => 'https://right.example']);
        $owner->projects()->attach($right, ['role' => 'owner']);

        // One sample at a time per account, while the first is still live.
        $this->assertFalse(app(TrialEligibility::class)->mayHaveAPreview($owner, $right));

        $this->actingAs($owner)
            ->post("/projects/{$project->getKey()}/archive", ['confirmation' => 'Wrong address'])
            ->assertRedirect();

        $this->assertTrue(app(TrialEligibility::class)->mayHaveAPreview($owner, $right->fresh()));
    }

    /** @return array<string, mixed> */
    private function subscriptionEvent(Project $project, string $status, string $type = 'customer.subscription.updated'): array
    {
        $start = now()->startOfDay();

        return [
            'id' => 'evt_'.$type,
            'type' => $type,
            'created' => now()->getTimestamp(),
            'data' => ['object' => [
                'id' => 'sub_test',
                'status' => $status,
                'customer' => 'cus_test',
                'current_period_start' => $start->getTimestamp(),
                'current_period_end' => $start->copy()->addMonth()->getTimestamp(),
                'trial_end' => null,
                'canceled_at' => null,
                'metadata' => ['project_id' => $project->getKey(), 'plan' => 'medium', 'plan_version' => '1'],
                'items' => ['data' => [['price' => ['id' => 'price_medium']]]],
            ]],
        ];
    }

    private function provider(): FakeBillingProvider
    {
        $provider = app(BillingProvider::class);
        $this->assertInstanceOf(FakeBillingProvider::class, $provider);

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{User, Project}
     */
    private function ownerWithProject(string $name, array $attributes = []): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['name' => $name, ...$attributes]);
        $owner->projects()->attach($project, ['role' => 'owner']);

        return [$owner, $project];
    }
}
