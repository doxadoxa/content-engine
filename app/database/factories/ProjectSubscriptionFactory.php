<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use Database\Factories\Concerns\ResolvesProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ProjectSubscription>
 */
class ProjectSubscriptionFactory extends Factory
{
    use ResolvesProject;

    protected $model = ProjectSubscription::class;

    /**
     * A paying project, mid-period. The dull case, which is what a factory
     * should build — every interesting state below is a state named out loud.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = Carbon::now()->subDays(3);

        return [
            'project_id' => fn (): mixed => $this->resolveProject(),
            'billing_user_id' => null,
            'plan' => 'medium',
            'plan_version' => 1,
            'status' => BillingStatus::Active,
            'limit_overrides' => [],
            'period_started_at' => $started,
            'period_ends_at' => $started->copy()->addMonth(),
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'canceled_at' => null,
        ];
    }

    public function forProject(Project $project): self
    {
        return $this->state(fn (): array => ['project_id' => $project->getKey()]);
    }

    public function plan(string $key): self
    {
        return $this->state(fn (): array => ['plan' => $key]);
    }

    /** Inside the free window, with time left on it. */
    public function trialing(): self
    {
        return $this->state(function (): array {
            $started = Carbon::now()->subDay();

            return [
                'plan' => 'trial',
                'status' => BillingStatus::Trialing,
                'period_started_at' => $started,
                'period_ends_at' => $started->copy()->addDays(3),
                'trial_ends_at' => $started->copy()->addDays(3),
            ];
        });
    }

    /**
     * A trial whose three days are up.
     *
     * Still `trialing`, deliberately: that is the state the row is really in
     * until `billing:sweep` gets to it, and entitlement has to refuse it on the
     * dates alone. A test that set this to `canceled` would be testing the
     * sweep rather than the reading.
     */
    public function trialExpired(): self
    {
        return $this->state(function (): array {
            $started = Carbon::now()->subDays(5);

            return [
                'plan' => 'trial',
                'status' => BillingStatus::Trialing,
                'period_started_at' => $started,
                'period_ends_at' => $started->copy()->addDays(3),
                'trial_ends_at' => $started->copy()->addDays(3),
            ];
        });
    }

    /** A failed payment, inside the grace. */
    public function pastDue(): self
    {
        return $this->state(fn (): array => [
            'status' => BillingStatus::PastDue,
            'grace_ends_at' => Carbon::now()->addDays(5),
        ]);
    }

    public function canceled(): self
    {
        return $this->state(fn (): array => [
            'status' => BillingStatus::Canceled,
            'canceled_at' => Carbon::now()->subDay(),
        ]);
    }
}
