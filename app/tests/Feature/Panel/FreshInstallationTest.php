<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\OnboardingStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A project that has not finished the wizard is a state, not a fault.
 *
 * `ProjectManager::live()` excludes a project whose onboarding is still `draft`
 * — deliberately, because a dashboard for a project with no brief and no plan is
 * worse than none. So on a fresh installation there is no current project on any
 * request, and every screen in the sidebar is reachable in that condition.
 *
 * /today and /dashboard have always degraded into an empty screen, and the one
 * that replaced them has to as well.
 */
final class FreshInstallationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();

        // A project mid-wizard, which is what an installation looks like
        // between "create" and "launch".
        $project = Project::factory()->create(['onboarding_status' => OnboardingStatus::Draft]);
        $this->operator->projects()->attach($project, ['role' => 'owner']);
    }

    #[Test]
    public function the_screens_that_already_degraded_still_do(): void
    {
        // One route where there were two: `today.index` no longer exists and
        // `dashboard` is a redirect to this same screen, both because the three
        // landing screens became one. The property under test is unchanged —
        // this screen degrades on a fresh installation rather than erroring.
        $this->actingAs($this->operator)->get(route('home.index'))->assertOk();
    }
}
