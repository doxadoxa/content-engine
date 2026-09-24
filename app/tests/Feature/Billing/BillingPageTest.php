<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\BillingStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_current_paid_offer_is_identified_with_its_actual_allowance(): void
    {
        [$user, $project] = $this->member();
        $subscription = ProjectSubscription::factory()->forProject($project)->create([
            'plan' => 'growth', 'stripe_id' => 'sub_fixture',
            'limit_overrides' => ['articles' => 35],
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)->get('/billing')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/index')
            ->where('entitlement.plan.name', 'Growth')
            ->where('entitlement.plan.price_cents', 8900)
            ->where('entitlement.plan.currency', 'usd')
            ->where('entitlement.status', 'active')
            ->where('entitlement.usage.articles.limit', 35)
            ->where('entitlement.period_ends_at', $subscription->period_ends_at?->toIso8601String())
            ->where('subscription_details.period_started_at', $subscription->periodStart()->toIso8601String())
            ->where('subscription_details.limits.0.key', 'articles')
            ->where('subscription_details.limits.0.value', 35)
            // The cost ceiling is the one limit nobody was sold.
            ->where('subscription_details.limits', fn (mixed $limits): bool => $limits instanceof Collection && ! $limits->contains('key', 'cost_micros'))
            ->where('has_provider', true)
            ->where('plans.0.key', 'starter')
            ->where('plans.0.current', false)
            ->where('plans.1.key', 'growth')
            ->where('plans.1.current', true));

        // Drawing the page asks the provider nothing.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_selected_paid_plan_still_reports_trial_allowances_and_expiration(): void
    {
        [$user, $project] = $this->member();
        $subscription = ProjectSubscription::factory()->forProject($project)->trialExpired()->create([
            'plan' => 'starter', 'stripe_id' => 'sub_trial_fixture',
        ]);

        $this->actingAs($user)->get('/billing')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entitlement.plan.name', 'Starter')
            ->where('entitlement.status', BillingStatus::Trialing->value)
            ->where('entitlement.refusal.code', 'trial_ended')
            ->where('entitlement.usage.articles.limit', 3)
            ->where('subscription_details.limits.0.value', 12)
            ->where('entitlement.trial_ends_at', $subscription->trial_ends_at?->toIso8601String())
            ->where('plans.0.current', true));
    }

    #[Test]
    public function a_project_without_a_subscription_has_no_current_plan_or_dates(): void
    {
        [$user] = $this->member();

        $this->actingAs($user)->get('/billing')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entitlement.plan', null)
            ->where('subscription_details', null)
            ->where('has_provider', false));
    }

    /** @return array{User, Project} */
    private function member(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->unbilled()->create();
        $user->projects()->attach($project, ['role' => $role]);
        app(CurrentProject::class)->set($project);

        return [$user, $project];
    }
}
