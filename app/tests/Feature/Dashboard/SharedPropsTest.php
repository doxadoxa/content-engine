<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What every page carries regardless of which controller answered.
 */
final class SharedPropsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_dashboard_does_not_query_per_project(): void
    {
        // The switcher's options, the viewer's role and the project cards all
        // want the membership list, and Inertia evaluates the shared props as
        // separate closures — so it is easy for each to fetch its own. A
        // handful of whole-list queries is fine; one per project is the N+1
        // that only shows up once somebody runs more than a few brands.
        $projects = 12;

        $operator = User::factory()->create();
        $operator->projects()->attach(Project::factory()->count($projects)->create());

        DB::enableQueryLog();
        $this->actingAs($operator)->get('/dashboard')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $membershipQueries = count(array_filter(
            $queries,
            fn (array $query): bool => str_contains((string) $query['query'], 'project_user'),
        ));

        $this->assertLessThan($projects, $membershipQueries);
    }

    #[Test]
    public function the_operators_role_in_the_current_project_is_shared(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create();
        $operator->projects()->attach($project, ['role' => 'owner']);

        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.project.role', 'owner')
            );
    }

    #[Test]
    public function a_successful_action_flashes_a_toast(): void
    {
        // Through Inertia's own flash rather than a shared prop of our own: the
        // toast hook listens for Inertia's `flash` event, and a session value
        // under a different key never reaches it.
        $operator = User::factory()->create();
        $alpha = Project::factory()->create(['name' => 'Alpha']);
        $beta = Project::factory()->create(['name' => 'Beta']);
        $operator->projects()->attach([$alpha->getKey(), $beta->getKey()]);

        $this->actingAs($operator)
            ->from('/dashboard')
            ->post("/projects/{$beta->getKey()}/switch");

        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Now working in Beta', escape: false);
    }

    #[Test]
    public function a_guest_page_carries_no_project(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/login')
                ->where('auth.project', null)
                ->where('auth.projects', [])
            );
    }
}
