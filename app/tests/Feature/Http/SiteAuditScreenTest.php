<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Enums\AuditSeverity;
use App\Enums\PipelineRunStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The site audit screen.
 *
 * Two things it has to keep straight, and both are about a sweep that has not
 * finished. The screen must show the last *complete* answer rather than a
 * half-built one — otherwise an operator who presses Recheck watches their
 * score collapse to nothing and climb back — and it must not let them start a
 * second crawl of a customer's server while the first is running.
 */
final class SiteAuditScreenTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'name' => 'Cleaning Point',
            'website_url' => 'https://example.test',
        ]);

        $this->user = User::factory()->create();
        $this->user->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function it_renders_before_the_first_sweep(): void
    {
        $this->actingAs($this->user)
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('audit/index')
                ->where('audit', null)
                ->where('is_running', false)
                // The list of what gets checked comes from the registry, so it
                // is there before anything has been measured — this screen can
                // explain itself on a project that has never been read.
                ->has('checks', 13));
    }

    #[Test]
    public function it_shows_the_newest_finished_sweep_with_its_pages_worst_first(): void
    {
        $audit = SiteAudit::factory()->create(['health_score' => 74, 'pages_crawled' => 2]);

        $bad = SiteAuditPage::factory()->create([
            'site_audit_id' => $audit->getKey(),
            'url' => 'https://example.test/broken',
            'issues_count' => 3,
            'score' => 60,
        ]);
        SiteAuditPage::factory()->create([
            'site_audit_id' => $audit->getKey(),
            'url' => 'https://example.test/fine',
            'issues_count' => 0,
            'score' => 100,
        ]);

        SiteAuditIssue::factory()->severity(AuditSeverity::High)->create([
            'site_audit_id' => $audit->getKey(),
            'site_audit_page_id' => $bad->getKey(),
            'check_key' => 'canonical_url',
        ]);

        $this->actingAs($this->user)
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('audit.health_score', 74)
                ->where('pages.0.url', 'https://example.test/broken')
                ->where('pages.0.issues.0.severity', 'high')
                // The label comes from the check class, not from the row: an
                // issue written by a build that has since dropped the check
                // still has to render.
                ->where('pages.0.issues.0.label', 'Canonical URL'));
    }

    #[Test]
    public function a_sweep_still_running_does_not_replace_the_last_complete_one(): void
    {
        SiteAudit::factory()->create(['health_score' => 91]);
        SiteAudit::factory()->running()->create();

        $this->actingAs($this->user)
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('audit.health_score', 91));
    }

    #[Test]
    public function an_unmeasured_score_reaches_the_screen_as_null_and_not_zero(): void
    {
        SiteAudit::factory()->withoutSpeed()->create(['health_score' => 88]);

        $this->actingAs($this->user)
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('audit.speed_score', null)
                ->where('groups.2.score', null));
    }

    #[Test]
    public function recheck_starts_a_sweep(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post('/audit/recheck')->assertRedirect();

        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('pipeline', 'site_audit')->count(),
        );
    }

    #[Test]
    public function recheck_refuses_to_crawl_a_customer_site_twice_at_once(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post('/audit/recheck')->assertRedirect();
        $this->actingAs($this->user)->post('/audit/recheck')->assertRedirect();

        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('pipeline', 'site_audit')->count(),
            'An impatient second press must cost the customer nothing.',
        );
    }

    #[Test]
    public function a_project_with_no_website_is_told_so_rather_than_starting_a_run(): void
    {
        Queue::fake();

        $this->project->forceFill(['website_url' => null])->save();

        $this->actingAs($this->user)->post('/audit/recheck')->assertRedirect();

        $this->assertSame(0, PipelineRun::acrossProjects()->where('pipeline', 'site_audit')->count());
    }

    #[Test]
    public function a_fix_plan_needs_a_finished_sweep_to_read(): void
    {
        Queue::fake();

        SiteAudit::factory()->running()->create();

        $this->actingAs($this->user)->post('/audit/fix-plan')->assertRedirect();

        // A plan drawn from half the findings is wrong in the way that is
        // hardest to notice, and it costs a model call to be wrong.
        $this->assertSame(
            0,
            PipelineRun::acrossProjects()->where('pipeline', 'site_audit_fix_plan')->count(),
        );
    }

    #[Test]
    public function a_fix_plan_is_written_once_per_sweep_at_a_time(): void
    {
        Queue::fake();

        SiteAudit::factory()->create();

        $this->actingAs($this->user)->post('/audit/fix-plan')->assertRedirect();
        $this->actingAs($this->user)->post('/audit/fix-plan')->assertRedirect();

        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('pipeline', 'site_audit_fix_plan')->count(),
        );
    }

    #[Test]
    public function a_fix_plan_that_failed_is_reported_rather_than_leaving_the_button_looking_untouched(): void
    {
        $audit = SiteAudit::factory()->create();

        PipelineRun::query()->create([
            'pipeline' => 'site_audit_fix_plan',
            'status' => PipelineRunStatus::Failed,
            'input' => ['site_audit_id' => (string) $audit->getKey()],
            'error' => ['message' => 'The openai call failed: timed out'],
            'started_at' => now()->subMinutes(4),
            'finished_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fix_plan_run.status', 'failed')
                ->where('fix_plan_run.error', 'The openai call failed: timed out')
                ->where('is_writing_fix_plan', false));
    }

    #[Test]
    public function a_fix_plan_still_running_reports_how_long_it_has_been(): void
    {
        $audit = SiteAudit::factory()->create();

        PipelineRun::query()->create([
            'pipeline' => 'site_audit_fix_plan',
            'status' => PipelineRunStatus::Running,
            'input' => ['site_audit_id' => (string) $audit->getKey()],
            'started_at' => now()->subMinutes(9),
        ]);

        // The clock is the server's: a run going for nine minutes has to be
        // distinguishable from one that started a moment ago, and the client
        // cannot tell without reading a clock while it renders.
        $this->actingAs($this->user)
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('is_writing_fix_plan', true)
                ->where('fix_plan_run.running_for_minutes', 9));
    }

    #[Test]
    public function a_sweep_nobody_has_asked_a_plan_for_says_nothing_about_one(): void
    {
        SiteAudit::factory()->create();

        $this->actingAs($this->user)
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('fix_plan_run', null));
    }

    #[Test]
    public function the_fix_plan_is_rendered_rather_than_handed_over_as_markup(): void
    {
        SiteAudit::factory()->create([
            'fix_plan' => "### Canonicals first\n\n<script>alert(1)</script>",
            'fix_plan_written_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->get('/audit')
            ->assertOk()
            ->assertInertia(function ($page) {
                $plan = $page->toArray()['props']['audit']['fix_plan'];

                $this->assertStringContainsString('<h3>', $plan);
                // The model is not a trusted author, and this reaches the page
                // through dangerouslySetInnerHTML.
                $this->assertStringNotContainsString('<script>', $plan);
            });
    }

    #[Test]
    public function another_tenants_sweep_is_not_visible(): void
    {
        $other = Project::factory()->create(['website_url' => 'https://elsewhere.test']);

        app(CurrentProject::class)->run($other, function (): void {
            SiteAudit::factory()->create(['health_score' => 12]);
        });

        $this->actingAs($this->user)
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('audit', null));
    }
}
