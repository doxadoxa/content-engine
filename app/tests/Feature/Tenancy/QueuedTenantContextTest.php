<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Jobs\RecordCurrentProject;
use Tests\TestCase;

/**
 * The tenant has to survive the queue, not just the request.
 *
 * Every pipeline step from phase 3 onward runs on a worker, and one worker
 * handles jobs from every project in turn. A step that reads whatever tenant
 * the worker happened to be holding writes one project's article into another's.
 *
 * Deliberately a real payload round trip on the database driver rather than the
 * sync driver: sync never serialises, so these would pass whether or not the
 * tenant is carried in the payload — which is the entire thing under test.
 */
final class QueuedTenantContextTest extends TestCase
{
    use RefreshDatabase;

    private CurrentProject $currentProject;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);

        $this->currentProject = app(CurrentProject::class);
    }

    #[Test]
    public function a_job_runs_under_the_project_that_dispatched_it(): void
    {
        $alpha = Project::factory()->create();

        $this->dispatchUnder($alpha);

        // The worker starts with no tenant of its own — exactly the situation
        // that makes an ambient singleton answer with the previous job's.
        $this->currentProject->forget();

        $this->work();

        $this->assertSame([$alpha->getKey()], RecordCurrentProject::seen());
    }

    #[Test]
    public function a_job_dispatched_without_a_tenant_does_not_inherit_the_previous_one(): void
    {
        $alpha = Project::factory()->create();

        $this->dispatchUnder($alpha);
        $this->dispatchUnder(null);

        $this->work();
        $this->work();

        // The leak that only appears under load: two projects' work on one
        // worker, and the second job silently reading the first one's tenant.
        $this->assertSame([$alpha->getKey(), 'none'], RecordCurrentProject::seen());
    }

    private function dispatchUnder(?Project $project): void
    {
        $this->currentProject->run($project, function (): void {
            // A void closure on purpose. PendingDispatch builds the payload in
            // its destructor, so returning it from run() would hand it back to
            // the caller and only dispatch after the previous tenant had been
            // restored — with the context of the wrong project, or none.
            RecordCurrentProject::dispatch();
        });
    }

    private function work(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('queue:work', ['--once' => true]);

        $command->assertSuccessful();
    }
}
