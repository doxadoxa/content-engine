<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Context\Events\ContextHydrated;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CurrentProjectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resolves_the_model_behind_an_id_set_as_a_string(): void
    {
        $project = Project::factory()->create();

        $current = app(CurrentProject::class);
        $current->set($project->getKey());

        $this->assertTrue($current->get()?->is($project));
    }

    #[Test]
    public function locales_survive_a_round_trip_through_the_database(): void
    {
        // The json cast is what makes locales a set rather than a string, and
        // it is the piece phase 2 builds locale groups on top of.
        $project = Project::factory()->multilingual()->create();

        $reloaded = $project->refresh();

        $this->assertSame(['pt-PT', 'en'], $reloaded->locales);
        $this->assertTrue($reloaded->supportsLocale('pt-PT'));
        $this->assertFalse($reloaded->supportsLocale('de'));
    }

    #[Test]
    public function a_hydrated_context_re_reads_the_project(): void
    {
        // A Horizon worker outlives thousands of jobs. Holding on to the
        // Project as it looked when the worker first saw it would mean pausing
        // a project takes effect only after a restart.
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);

        $current = app(CurrentProject::class);
        $current->set($project);

        $this->assertSame(ProjectStatus::Active, $current->get()?->status);

        Project::query()->whereKey($project->getKey())->update(['status' => ProjectStatus::Paused]);

        // What the queue does between jobs: hydrate the next job's context.
        Event::dispatch(new ContextHydrated(Context::getFacadeRoot()));

        $this->assertSame(ProjectStatus::Paused, $current->get()->status);
    }

    #[Test]
    public function forgetting_the_tenant_drops_the_resolved_model(): void
    {
        $project = Project::factory()->create();

        $current = app(CurrentProject::class);
        $current->set($project);
        $current->get();
        $current->forget();

        $this->assertNull($current->get());
        $this->assertFalse($current->isSet());
    }
}
