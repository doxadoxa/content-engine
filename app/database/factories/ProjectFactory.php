<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OnboardingStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->domainWord();

        return [
            'name' => Str::headline($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'timezone' => 'Europe/Lisbon',
            'default_locale' => 'en',
            'locales' => ['en'],
            'status' => ProjectStatus::Active,
            'weekly_target' => 3,
            'research_seeds' => ['window cleaning'],
            'market' => 'us',
            'is_ymyl' => false,
            'autopublish' => false,
            'original_data' => [],
            'authors' => [['name' => 'Operator', 'title' => 'Editor']],
            // Factories make projects that already exist, so they are past the
            // wizard. `onboarding()` is the state for testing the wizard.
            'onboarding_status' => OnboardingStatus::Active,
            'website_url' => 'https://example.test',
            'onboarded_at' => now(),
        ];
    }

    /**
     * A project publishing in more than one language — the Cleaning Point shape
     * (§1), and the case that catches locale handling written for one.
     */
    public function multilingual(): static
    {
        return $this->state(fn (): array => [
            'default_locale' => 'pt-PT',
            'locales' => ['pt-PT', 'en'],
        ]);
    }

    /** Finance or health: fact-checking and a real author are required (§1). */
    public function ymyl(): static
    {
        return $this->state(fn (): array => [
            'is_ymyl' => true,
            'authors' => [['name' => 'Taras Dovgal', 'title' => 'Editor']],
        ]);
    }

    /** Mid-wizard: created, nothing decided yet. */
    public function onboarding(): static
    {
        return $this->state(fn (): array => [
            'onboarding_status' => OnboardingStatus::Draft,
            'onboarded_at' => null,
            'research_seeds' => [],
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => ProjectStatus::Paused]);
    }

    /**
     * Every project comes with a subscription, because in the running system
     * every project has one.
     *
     * Onboarding mints a trial the moment the engine starts, and the
     * grandfathering migration gave one to every project that predates billing
     * — so a project with no subscription is not a normal state, it is the
     * anomaly. A factory that built the anomaly by default would make hundreds
     * of tests about publishing, planning and drafting fail for a billing
     * reason, which is the fastest way to teach everybody to stop reading
     * billing failures.
     *
     * The anomaly is still buildable, out loud, with {@see unbilled()} — and it
     * has its own tests, because failing closed is the behaviour that matters
     * most here.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            if (ProjectSubscription::query()->where('project_id', $project->getKey())->exists()) {
                return;
            }

            ProjectSubscription::factory()->forProject($project)->create();
        });
    }

    /**
     * A project nobody ever put on a plan.
     *
     * Written as a deletion after the fact rather than a flag consulted before
     * it, because factory callbacks run in the order they were added and a
     * state cannot reach back into `configure()`. Two writes instead of one, in
     * a test, to keep the default honest.
     */
    public function unbilled(): static
    {
        return $this->afterCreating(function (Project $project): void {
            ProjectSubscription::query()->where('project_id', $project->getKey())->delete();
        });
    }
}
