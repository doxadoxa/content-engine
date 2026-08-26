<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\OnboardingStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia;
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
 * /today and /dashboard have always degraded into an empty screen. /engage
 * answered 409, which put an error page in front of the first thing a new
 * operator clicks.
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
    public function the_duty_queue_degrades_like_every_other_screen(): void
    {
        $this->actingAs($this->operator)
            ->get(route('engage.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('engage/index')
                ->has('conversations.data', 0)
                ->where('answered_today', 0)
                // The same props either way: a render that dropped one would
                // make the page's contract depend on a state nobody looks at.
                ->has('reasons')
                ->has('text_limit')
                ->has('foreign_replies_allowed')
            );
    }

    #[Test]
    public function the_screens_that_already_degraded_still_do(): void
    {
        // The comparison the fix is measured against, kept as an assertion so
        // that "identical condition" stays true rather than being a claim in a
        // commit message.
        //
        // One route where there were two: `today.index` no longer exists and
        // `dashboard` is a redirect to this same screen, both because the three
        // landing screens became one. The property under test is unchanged —
        // this screen degrades on a fresh installation rather than erroring.
        $this->actingAs($this->operator)->get(route('home.index'))->assertOk();
    }

    #[Test]
    public function reaping_signals_on_a_fresh_installation_is_a_success(): void
    {
        // Scheduled nightly at 03:20, so this ran and failed every night until
        // somebody launched something. A named project that does not exist is
        // still an error — that one is a typo.
        /** @var PendingCommand $ok */
        $ok = $this->artisan('signals:reap');
        $ok->assertSuccessful()->run();
        /** @var PendingCommand $missing */
        $missing = $this->artisan('signals:reap', ['project' => 'no-such-project']);
        $missing->assertFailed()->run();
    }
}
