<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\AuditSeverity;
use App\Enums\PipelineRunStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\SiteAuditFixPlanPipeline;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model's reading of one sweep.
 *
 * The interesting part is not the answer — that is a model call and a fake here
 * — but the prompt. Forty findings across a hundred pages are mostly the same
 * eight faults repeated by a template, and sending the repetition spends tokens
 * teaching the model that templates exist while crowding out the three findings
 * that are genuinely about one page.
 */
final class SiteAuditFixPlanTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'name' => 'Cleaning Point',
            'website_url' => 'https://example.test',
        ]);

        app(CurrentProject::class)->set($this->project);

        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;

        config()->set('queue.default', 'sync');
    }

    #[Test]
    public function it_stores_the_plan_as_markdown_against_the_sweep(): void
    {
        $this->models->willAnswer(['### Start with the canonicals']);

        $audit = $this->auditWithFindings();

        $run = $this->write($audit);

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $audit->refresh();

        // Markdown, not HTML: the column holds what the model wrote and the
        // controller renders it through SafeMarkdown, the same division
        // content_items makes between body_markdown and body_html.
        $this->assertSame('### Start with the canonicals', $audit->fix_plan);
        $this->assertNotNull($audit->fix_plan_written_at);
    }

    #[Test]
    public function one_fault_repeated_across_pages_reaches_the_model_once_with_a_count(): void
    {
        $this->models->willAnswer(['ok']);

        $audit = $this->auditWithFindings(pagesMissingDescriptions: 12);

        $this->write($audit);

        $prompt = $this->models->lastRequest()->prompt;

        $this->assertStringContainsString('12 pages', $prompt);
        $this->assertSame(
            1,
            substr_count($prompt, 'Meta description'),
            'Twelve identical findings are one job on one template, and should be described once.',
        );
    }

    #[Test]
    public function the_worst_findings_are_described_first(): void
    {
        $this->models->willAnswer(['ok']);

        $audit = $this->auditWithFindings();

        $this->write($audit);

        $prompt = $this->models->lastRequest()->prompt;

        $this->assertLessThan(
            strpos($prompt, '[MEDIUM]'),
            strpos($prompt, '[HIGH]'),
            'The model reads in order, so the order has to be the priority.',
        );
    }

    #[Test]
    public function a_sweep_that_found_nothing_says_so_without_spending_a_model_call(): void
    {
        $audit = SiteAudit::factory()->create();

        $this->write($audit);

        $this->assertSame('This sweep found nothing to fix.', $audit->refresh()->fix_plan);
        $this->assertSame([], $this->models->sent(), 'Nothing to order is not worth a model call.');
    }

    private function write(SiteAudit $audit): PipelineRun
    {
        return app(PipelineRunner::class)->start(
            SiteAuditFixPlanPipeline::key(),
            $this->project,
            ['site_audit_id' => (string) $audit->getKey()],
        );
    }

    private function auditWithFindings(int $pagesMissingDescriptions = 2): SiteAudit
    {
        $audit = SiteAudit::factory()->create(['pages_crawled' => $pagesMissingDescriptions + 1]);

        $canonical = SiteAuditPage::factory()->create([
            'site_audit_id' => $audit->getKey(),
            'url' => 'https://example.test/services',
        ]);

        SiteAuditIssue::factory()->create([
            'site_audit_id' => $audit->getKey(),
            'site_audit_page_id' => $canonical->getKey(),
            'check_key' => 'canonical_url',
            'severity' => AuditSeverity::High,
            'summary' => 'The canonical tag points at a different page.',
        ]);

        for ($i = 0; $i < $pagesMissingDescriptions; $i++) {
            $page = SiteAuditPage::factory()->create([
                'site_audit_id' => $audit->getKey(),
                'url' => "https://example.test/journal/post-{$i}",
            ]);

            SiteAuditIssue::factory()->create([
                'site_audit_id' => $audit->getKey(),
                'site_audit_page_id' => $page->getKey(),
                'check_key' => 'meta_description',
                'severity' => AuditSeverity::Medium,
                'summary' => 'This page has no meta description.',
            ]);
        }

        return $audit;
    }
}
