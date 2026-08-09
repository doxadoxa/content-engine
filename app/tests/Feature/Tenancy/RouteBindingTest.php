<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use App\Support\Tenancy\ProjectScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Route model binding, from a browser's point of view.
 *
 * Every other test in this suite sets the tenant straight into the container in
 * its `setUp`, which is convenient and hides the thing this file is for: on a
 * real request nothing has set it yet, and the only thing that will is the
 * middleware. If that runs *after* `SubstituteBindings`, a tenant-scoped model
 * is looked up with no tenant, {@see ProjectScope} fails
 * closed, and Laravel answers 404 — every detail page in the panel unreachable
 * by URL, with a green suite.
 */
final class RouteBindingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_scoped_model_in_the_url_resolves_from_the_session_alone(): void
    {
        [$operator, $project] = $this->operator();

        $unit = app(CurrentProject::class)->run(
            $project,
            fn (): ContentItem => ContentItem::factory()->published()->create(['title' => 'A unit']),
        );

        // Deliberately forgotten: this is what a browser has — a session cookie
        // and nothing else. Leaving it set would test the container, not the
        // middleware.
        app(CurrentProject::class)->forget();

        $this->actingAs($operator)
            ->withSession([ProjectManager::SESSION_KEY => $project->getKey()])
            ->get("/content/{$unit->getKey()}")
            ->assertOk();
    }

    #[Test]
    public function a_scoped_binding_on_a_post_resolves_too(): void
    {
        [$operator, $project] = $this->operator();

        $draft = app(CurrentProject::class)->run(
            $project,
            fn (): ContentItem => ContentItem::factory()->create(['state' => ContentItemState::Draft]),
        );

        app(CurrentProject::class)->forget();

        // Not only the read paths: approving from the queue binds the same way,
        // and a 404 there is an operator who cannot publish what they can see.
        $this->actingAs($operator)
            ->withSession([ProjectManager::SESSION_KEY => $project->getKey()])
            ->post("/content/{$draft->getKey()}/reject", [
                'reason' => 'off_brand',
                'note' => 'Not a fit for this calendar.',
            ])
            ->assertRedirect();

        $this->assertNotNull($draft->refresh()->reviewed_at);
    }

    #[Test]
    public function another_projects_model_is_still_not_reachable(): void
    {
        [$operator, $project] = $this->operator();

        $theirs = Project::factory()->create();

        $unit = app(CurrentProject::class)->run(
            $theirs,
            fn (): ContentItem => ContentItem::factory()->published()->create(),
        );

        app(CurrentProject::class)->forget();

        // The ordering fix must not have turned the scope off — a unit that
        // belongs to somebody else stays a 404.
        $this->actingAs($operator)
            ->withSession([ProjectManager::SESSION_KEY => $project->getKey()])
            ->get("/content/{$unit->getKey()}")
            ->assertNotFound();
    }

    /**
     * @return array{User, Project}
     */
    private function operator(): array
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create();
        $operator->projects()->attach($project);

        return [$operator, $project];
    }
}
