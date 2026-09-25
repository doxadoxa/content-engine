<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_default_locale_is_always_published(): void
    {
        [$operator, $project] = $this->operatorWithOwnProject();
        // Every plan on the list publishes in one language; two need an
        // arrangement that allows them.
        ProjectSubscription::query()->where('project_id', $project->getKey())->sole()
            ->update(['limit_overrides' => ['locales' => 2]]);

        $this->actingAs($operator)->patch("/projects/{$project->getKey()}", [
            'name' => $project->name,
            'slug' => $project->slug,
            'timezone' => 'UTC',
            'default_locale' => 'en',
            // Deliberately omits `en`.
            'locales' => ['de'],
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['en', 'de'], $project->refresh()->locales);
    }

    #[Test]
    public function a_slug_another_project_already_has_is_rejected(): void
    {
        [$operator, $project] = $this->operatorWithOwnProject();
        Project::factory()->create(['slug' => 'taken']);

        $this->actingAs($operator)
            ->patch("/projects/{$project->getKey()}", [
                'name' => 'Another',
                'slug' => 'taken',
                'timezone' => 'UTC',
                'default_locale' => 'en',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('slug');
    }

    #[Test]
    public function the_slug_cannot_be_changed_after_creation(): void
    {
        [$operator, $project] = $this->operatorWithOwnProject();

        $this->actingAs($operator)->patch("/projects/{$project->getKey()}", [
            'name' => 'Renamed',
            // The form sends the existing slug back; a crafted request could
            // send another. Either way the stored value must not move — it is
            // how receivers identify this project from phase 6 on.
            'slug' => 'something-else',
            'timezone' => 'UTC',
            'default_locale' => 'en',
            'status' => 'paused',
        ])->assertRedirect('/projects');

        $project->refresh();

        $this->assertSame('Renamed', $project->name);
        $this->assertSame(ProjectStatus::Paused, $project->status);
        $this->assertNotSame('something-else', $project->slug);
    }

    #[Test]
    public function an_operator_member_cannot_change_the_settings(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create(['timezone' => 'UTC']);
        $operator->projects()->attach($project, ['role' => 'operator']);

        // The settings route carries `project.owner`, so this is 403 rather
        // than a validation failure — the timezone decides when the project
        // publishes, and that is the owner's call.
        $this->actingAs($operator)->patch("/projects/{$project->getKey()}", [
            'name' => $project->name,
            'slug' => $project->slug,
            'timezone' => 'Europe/Lisbon',
            'default_locale' => 'en',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertSame('UTC', $project->refresh()->timezone);
    }

    #[Test]
    public function another_operators_project_cannot_be_opened(): void
    {
        $operator = $this->operatorWithProject();
        $theirs = Project::factory()->create();

        $this->actingAs($operator)
            ->get("/projects/{$theirs->getKey()}/edit")
            ->assertNotFound();
    }

    #[Test]
    public function another_operators_project_cannot_be_updated(): void
    {
        $operator = $this->operatorWithProject();
        $theirs = Project::factory()->create(['name' => 'Theirs']);

        $this->actingAs($operator)->patch("/projects/{$theirs->getKey()}", [
            'name' => 'Hijacked',
            'slug' => $theirs->slug,
            'timezone' => 'UTC',
            'default_locale' => 'en',
            'status' => 'active',
        ])->assertNotFound();

        $this->assertSame('Theirs', $theirs->refresh()->name);
    }

    #[Test]
    public function an_operator_member_can_use_the_project_but_cannot_change_its_configuration(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create(['name' => 'Shared project']);
        $operator->projects()->attach($project, ['role' => 'operator']);

        $this->actingAs($operator)->get('/home')->assertOk();
        $this->actingAs($operator)->get('/channels')->assertOk();
        $this->actingAs($operator)->get('/projects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('projects.0.role', 'operator')
            );

        $this->actingAs($operator)
            ->get("/projects/{$project->getKey()}/edit")
            ->assertForbidden();

        $this->actingAs($operator)->patch("/projects/{$project->getKey()}", [
            'name' => 'Hijacked',
            'slug' => $project->slug,
            'timezone' => 'UTC',
            'default_locale' => 'en',
            'status' => 'active',
        ])->assertForbidden();

        $this->actingAs($operator)->post('/channels', [
            'name' => 'Unapproved integration',
            'type' => 'pull_api',
            'config' => [],
            'secret' => 'token',
        ])->assertForbidden();

        $this->actingAs($operator)->put('/brief', ['tone' => 'Changed'])
            ->assertForbidden();

        $this->actingAs($operator)
            ->get("/projects/{$project->getKey()}/google/connect")
            ->assertForbidden();

        $this->assertSame('Shared project', $project->refresh()->name);
    }

    #[Test]
    public function switching_changes_what_the_next_page_is_about(): void
    {
        $operator = User::factory()->create();
        $alpha = Project::factory()->create(['name' => 'Alpha']);
        $beta = Project::factory()->create(['name' => 'Beta']);
        $operator->projects()->attach([$alpha->getKey(), $beta->getKey()]);

        $this->actingAs($operator)
            ->from('/home')
            ->post("/projects/{$beta->getKey()}/switch")
            ->assertRedirect('/home');

        $this->actingAs($operator)
            ->get('/home')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.project.name', 'Beta'));
    }

    #[Test]
    public function switching_to_a_project_you_are_not_in_is_refused(): void
    {
        $operator = $this->operatorWithProject();
        $theirs = Project::factory()->create();

        $this->actingAs($operator)
            ->post("/projects/{$theirs->getKey()}/switch")
            ->assertForbidden();
    }

    #[Test]
    public function a_stale_session_falls_back_instead_of_breaking_the_app(): void
    {
        // A project the operator has since been removed from is stale, not
        // hostile. Turning them away from every page for it would be a support
        // ticket, so the manager falls back to one they are in.
        [$operator, $project] = $this->operatorWithOwnProject();

        $this->actingAs($operator)
            ->withSession([ProjectManager::SESSION_KEY => 'a-project-that-is-gone'])
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.project.id', $project->getKey())
            );
    }

    private function operatorWithProject(): User
    {
        return $this->operatorWithOwnProject()[0];
    }

    /**
     * @return array{User, Project}
     */
    private function operatorWithOwnProject(): array
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create();
        $operator->projects()->attach($project, ['role' => 'owner']);

        return [$operator, $project];
    }
}
