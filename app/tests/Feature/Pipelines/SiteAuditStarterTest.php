<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Audit\SiteAuditStarter;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one place a sweep is started, and the guard that keeps it to one.
 *
 * Everything here is about not crawling a customer's server twice at once. The
 * three callers — the launch, the scheduler and the Recheck button — can all
 * arrive together in the ordinary course of a day: an operator who launches a
 * project and immediately presses Recheck, or double-clicks it, hits exactly
 * the window this is about.
 */
final class SiteAuditStarterTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->project = Project::factory()->create(['website_url' => 'https://example.test']);
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function it_starts_one_sweep(): void
    {
        $this->assertNotNull(app(SiteAuditStarter::class)->start($this->project));
        $this->assertSame(1, $this->sweeps());
    }

    #[Test]
    public function a_second_start_while_one_is_in_flight_is_refused(): void
    {
        $starter = app(SiteAuditStarter::class);

        $starter->start($this->project);

        $this->assertNull($starter->start($this->project));
        $this->assertSame(1, $this->sweeps());
    }

    #[Test]
    public function the_check_and_the_start_happen_under_one_lock(): void
    {
        // The guard is a read followed by a write, and without a lock every
        // caller can arrive in the gap between them. Asserted through the
        // queries the starter actually runs rather than by racing two
        // processes, which no suite can do reliably: a `for update` on the
        // project row is what makes the second caller wait for the first to
        // commit and then see its run.
        $locking = [];

        DB::listen(function ($query) use (&$locking): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $locking[] = $query->sql;
            }
        });

        app(SiteAuditStarter::class)->start($this->project);

        $this->assertNotSame([], $locking, 'The project row must be locked while the sweep is claimed.');
        $this->assertStringContainsString('projects', strtolower($locking[0]));
    }

    #[Test]
    public function a_fix_plan_is_claimed_under_the_same_lock(): void
    {
        $audit = SiteAudit::factory()->create();

        $starter = app(SiteAuditStarter::class);

        $this->assertNotNull($starter->startFixPlan($this->project, $audit));

        // Two presses in the same instant would otherwise buy two plans for one
        // sweep, and this is the branch that spends tokens.
        $this->assertNull($starter->startFixPlan($this->project, $audit));
        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('pipeline', 'site_audit_fix_plan')->count(),
        );
    }

    #[Test]
    public function a_project_with_no_website_is_refused_before_anything_is_locked(): void
    {
        $this->project->forceFill(['website_url' => null])->save();

        $this->assertNull(app(SiteAuditStarter::class)->start($this->project));
        $this->assertSame(0, $this->sweeps());
    }

    private function sweeps(): int
    {
        return PipelineRun::acrossProjects()
            ->where('project_id', $this->project->getKey())
            ->where('pipeline', 'site_audit')
            ->count();
    }
}
