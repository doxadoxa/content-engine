<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\ProjectManager;
use Carbon\CarbonImmutable;
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

        $this->actingAs($operator)->patch("/projects/{$project->getKey()}", [
            'name' => $project->name,
            'slug' => $project->slug,
            'timezone' => 'UTC',
            'default_locale' => 'en',
            // Deliberately omits `en`.
            'locales' => ['de'],
            'status' => 'active',
        ])->assertRedirect();

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
    public function duty_hours_are_stored_in_the_canonical_shape(): void
    {
        [$operator, $project] = $this->operatorWithOwnProject();

        $this->actingAs($operator)->patch("/projects/{$project->getKey()}", [
            'name' => $project->name,
            'slug' => $project->slug,
            'timezone' => 'Europe/Lisbon',
            'default_locale' => 'en',
            'status' => 'active',
            'duty_hours' => [
                'sat' => [['10:00', '12:00']],
                // Unpadded, out of order, touching, and one range that is not
                // one. What is written back is the reading the engine uses, so
                // an operator can see what their answer became.
                'mon' => [['12:00', '18:00'], ['9:00', '12:00'], ['18:00', '09:00']],
            ],
        ])->assertRedirect('/projects');

        $this->assertSame(
            ['mon' => [['09:00', '18:00']], 'sat' => [['10:00', '12:00']]],
            $project->refresh()->dutyHours()->toArray(),
        );
    }

    #[Test]
    public function a_duty_hours_payload_that_is_not_day_keyed_ranges_is_refused(): void
    {
        [$operator, $project] = $this->operatorWithOwnProject();

        $base = [
            'name' => $project->name,
            'slug' => $project->slug,
            'timezone' => 'Europe/Lisbon',
            'default_locale' => 'en',
            'status' => 'active',
        ];

        // A day key nobody can act on. The value object would drop it in
        // silence, which is the one outcome an operator cannot debug.
        $this->actingAs($operator)
            ->patch("/projects/{$project->getKey()}", [
                ...$base,
                'duty_hours' => ['funday' => [['09:00', '18:00']]],
            ])
            ->assertSessionHasErrors('duty_hours');

        $this->actingAs($operator)
            ->patch("/projects/{$project->getKey()}", [
                ...$base,
                'duty_hours' => ['mon' => 'all day'],
            ])
            ->assertSessionHasErrors('duty_hours.mon');

        $this->assertNull($project->refresh()->duty_hours);
    }

    #[Test]
    public function a_project_nobody_answered_the_question_for_is_never_on_duty(): void
    {
        $project = Project::factory()->create();

        $this->assertNull($project->refresh()->duty_hours);
        $this->assertTrue($project->dutyHours()->isEmpty());

        // Not "always available". An unanswered onboarding question that read
        // as round-the-clock cover would publish at 04:00 into a silence.
        $this->assertFalse($project->dutyHours()->covers(
            CarbonImmutable::parse('2026-07-01 10:00', 'UTC'),
            90,
            $project->timezone,
        ));
    }

    #[Test]
    public function the_column_is_read_back_through_the_value_object_after_a_round_trip(): void
    {
        // The jsonb → cast → fromArray path is the one production uses, and it
        // is not the same path as calling fromArray on a literal: Postgres
        // reorders jsonb keys, so week order has to be restored on read.
        $project = Project::factory()->create([
            'duty_hours' => ['wed' => [['9:00', '13:00']]],
            'timezone' => 'Europe/Lisbon',
        ]);

        $hours = $project->refresh()->dutyHours();

        $this->assertSame(['wed' => [['09:00', '13:00']]], $hours->toArray());

        // 08:30 UTC in July is 09:30 in Lisbon, which is inside the window.
        $this->assertTrue($hours->covers(
            CarbonImmutable::parse('2026-07-01 08:30', 'UTC'),
            90,
            $project->timezone,
        ));

        $this->assertFalse($hours->covers(
            CarbonImmutable::parse('2026-07-01 07:00', 'UTC'),
            90,
            $project->timezone,
        ));
    }

    #[Test]
    public function an_operator_member_cannot_change_the_duty_hours(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create();
        $operator->projects()->attach($project, ['role' => 'operator']);

        // The settings route carries `project.owner`, so this is 403 rather
        // than a validation failure — when somebody is on duty decides when the
        // project publishes, and that is the owner's call.
        $this->actingAs($operator)->patch("/projects/{$project->getKey()}", [
            'name' => $project->name,
            'slug' => $project->slug,
            'timezone' => 'Europe/Lisbon',
            'default_locale' => 'en',
            'status' => 'active',
            'duty_hours' => ['mon' => [['00:00', '24:00']]],
        ])->assertForbidden();

        $this->assertNull($project->refresh()->duty_hours);
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
