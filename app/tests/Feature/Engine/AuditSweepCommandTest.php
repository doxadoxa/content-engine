<?php

declare(strict_types=1);

namespace Tests\Feature\Engine;

use App\Enums\ProjectStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The weekly re-read.
 *
 * Runs daily and starts almost nothing, which is the property worth testing: a
 * daily wakeup with a per-project freshness check spreads the crawls across the
 * week by when each project last had one, where a weekly schedule entry would
 * point every project's crawl at the same minute — a thundering herd aimed at
 * customers' servers.
 */
final class AuditSweepCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function a_project_that_has_never_been_read_is_swept(): void
    {
        $project = Project::factory()->create(['website_url' => 'https://example.test']);

        $this->sweep('audit:sweep');

        $this->assertSame(1, $this->sweeps($project));
    }

    #[Test]
    public function a_project_read_this_week_is_left_alone(): void
    {
        $project = Project::factory()->create(['website_url' => 'https://example.test']);

        app(CurrentProject::class)->run($project, function (): void {
            SiteAudit::factory()->create(['created_at' => now()->subDays(2)]);
        });

        $this->sweep('audit:sweep');

        $this->assertSame(0, $this->sweeps($project));
    }

    #[Test]
    public function a_project_read_a_fortnight_ago_is_due(): void
    {
        $project = Project::factory()->create(['website_url' => 'https://example.test']);

        app(CurrentProject::class)->run($project, function (): void {
            SiteAudit::factory()->create(['created_at' => now()->subDays(14)]);
        });

        $this->sweep('audit:sweep');

        $this->assertSame(1, $this->sweeps($project));
    }

    #[Test]
    public function a_sweep_that_failed_halfway_still_holds_the_next_one_off(): void
    {
        $project = Project::factory()->create(['website_url' => 'https://example.test']);

        app(CurrentProject::class)->run($project, function (): void {
            SiteAudit::factory()->running()->create(['created_at' => now()->subDay()]);
        });

        $this->sweep('audit:sweep');

        // A site whose audit cannot complete would otherwise be crawled every
        // single night by a scheduler retrying it — the most expensive possible
        // response to a site that is already unwell.
        $this->assertSame(0, $this->sweeps($project));
    }

    #[Test]
    public function a_paused_project_is_not_crawled(): void
    {
        $project = Project::factory()->create([
            'website_url' => 'https://example.test',
            'status' => ProjectStatus::Paused,
        ]);

        $this->sweep('audit:sweep');

        $this->assertSame(0, $this->sweeps($project));
    }

    #[Test]
    public function a_project_with_no_website_is_skipped_rather_than_failed(): void
    {
        $project = Project::factory()->create(['website_url' => null]);

        $this->sweep('audit:sweep');

        $this->assertSame(0, $this->sweeps($project));
    }

    #[Test]
    public function force_reads_a_site_that_is_not_due(): void
    {
        $project = Project::factory()->create(['website_url' => 'https://example.test']);

        app(CurrentProject::class)->run($project, function (): void {
            SiteAudit::factory()->create(['created_at' => now()->subHour()]);
        });

        $this->sweep('audit:sweep --force');

        $this->assertSame(1, $this->sweeps($project));
    }

    #[Test]
    public function a_dry_run_starts_nothing(): void
    {
        $project = Project::factory()->create(['website_url' => 'https://example.test']);

        $this->sweep('audit:sweep --dry');

        $this->assertSame(0, $this->sweeps($project));
    }

    /**
     * `artisan()` is declared as returning `PendingCommand|int`, and
     * assertSuccessful() only records the expectation — the command runs in
     * __destruct(), so it has to be run explicitly. Same shape as
     * {@see EngineTickTest::tick()}.
     */
    private function sweep(string $command): void
    {
        /** @var PendingCommand $pending */
        $pending = $this->artisan($command);

        $pending->assertSuccessful()->run();
    }

    private function sweeps(Project $project): int
    {
        return PipelineRun::acrossProjects()
            ->where('project_id', $project->getKey())
            ->where('pipeline', 'site_audit')
            ->count();
    }
}
