<?php

declare(strict_types=1);

namespace Tests\Feature\Measurements;

use App\Enums\ProjectStatus;
use App\Feedback\Contracts\AnalyticsGateway;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\FakeAnalytics;
use App\Feedback\FakeSearchConsole;
use App\Feedback\Measurements\AnalyticsRow;
use App\Feedback\Measurements\PagePerformance;
use App\Feedback\Measurements\ReadResult;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SearchRow;
use App\Feedback\Measurements\SynchronizePageMeasurements;
use App\Feedback\Measurements\SyncPageMeasurementsJob;
use App\Feedback\Measurements\Windows;
use App\Models\MeasurementRead;
use App\Models\PageAnalyticsMetric;
use App\Models\PageMetric;
use App\Models\PageQueryMetric;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\PropertyAnalyticsMetric;
use App\Models\SitePage;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PageMeasurementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-15 18:00:00', 'UTC'));
    }

    #[Test]
    public function windows_are_two_adjacent_inclusive_periods_of_twenty_eight_final_days(): void
    {
        $windows = new Windows;
        $this->assertSame('2026-09-12', $windows->to->toDateString());
        $this->assertSame('2026-08-16', $windows->currentFrom->toDateString());
        $this->assertSame('2026-08-15', $windows->previousTo->toDateString());
        $this->assertSame('2026-07-19', $windows->from->toDateString());
        $this->assertSame('2026-09-12', (new Windows(Carbon::parse('2027-01-01')))->to->toDateString());
    }

    #[Test]
    public function history_covers_tracked_service_pages_and_canonical_aliases_idempotently(): void
    {
        $project = $this->project();
        $page = SitePage::factory()->notAnArticle()->create(['url' => 'https://example.com/service-old', 'canonical_url' => 'https://example.com/service/', 'tracked_at' => now()]);
        SitePage::factory()->create(['url' => 'https://example.com/untracked']);
        $this->search()->willReadPages(new ReadResult(ReadStatus::Complete, [
            new SearchRow($page->url, '2026-08-15', 40, 4, 8.0),
            new SearchRow('https://example.com/service/?utm_source=ad', '2026-08-16', 30, 2, 10.0),
            new SearchRow('https://example.com/service/', '2026-08-16', 10, 1, 6.0),
            new SearchRow('https://example.com/untracked', '2026-08-16', 900, 9, 1.0),
        ]))->willReadPages(new ReadResult(ReadStatus::Complete, [
            new SearchRow('https://example.com/service/', '2026-08-16', 15, 1, 12.0, 'cleaning lisbon'),
        ]), true);
        $this->analytics()->willReadPurchases(new ReadResult(ReadStatus::Complete, [
            new AnalyticsRow('/service/', '2026-08-16', 'Organic Search', 20, 2, 100000000, 20000000, 80000000, 'EUR'),
        ]));
        app(SynchronizePageMeasurements::class)->sync($project);
        app(SynchronizePageMeasurements::class)->sync($project);
        $this->assertSame(2, PageMetric::query()->count());
        $this->assertSame(1, PageQueryMetric::query()->count());
        $this->assertSame(1, PropertyAnalyticsMetric::query()->count());
        $this->assertSame(90, PageMetric::query()->where('measured_on', '2026-08-16')->firstOrFail()->position_tenths);
        $report = app(PagePerformance::class)->forProject($project);
        $this->assertCount(1, $report['pages']);
        $row = $report['pages'][0];
        $this->assertSame(40, $row['current']['search']['impressions']);
        $this->assertSame(40, $row['previous']['search']['impressions']);
        $this->assertSame(1, $row['current']['search']['observed_days']);
        $this->assertTrue($row['search_comparison_available']);
        $this->assertSame(15, $row['current']['queries'][0]['impressions']);
        $this->assertNull($row['current']['analytics']['reported_purchases']);
        $this->assertSame('unavailable', $row['current']['analytics']['status']);
        $this->assertSame(2, $report['analytics']['current']['reported_purchases']);
        $this->assertNull($report['analytics']['landing_origin']);
        $this->assertSame(0, PageAnalyticsMetric::query()->count());
        $this->assertNull($row['current']['analytics']['new_customers']);
        $this->assertSame(80000000, $report['analytics']['current']['revenue_by_currency'][0]['net_revenue_micros']);
        $this->assertSame('unknown', $row['index_status']);
    }

    #[Test]
    public function unavailable_sources_and_sparse_queries_have_null_totals_not_invented_zeros(): void
    {
        $project = $this->project();
        SitePage::factory()->create(['tracked_at' => now()]);
        $this->search()->willBeUnconfigured();
        $this->analytics()->willBeUnconfigured();
        app(SynchronizePageMeasurements::class)->sync($project);
        $report = app(PagePerformance::class)->forProject($project);
        $this->assertSame('unavailable', $report['sources']['gsc_pages']['status']);
        $this->assertSame('unavailable', $report['sources']['ga4_property_landing_paths']['status']);
        $this->assertNull($report['pages'][0]['current']['search']['impressions']);
        $this->assertNull($report['pages'][0]['current']['analytics']['reported_purchases']);
        $this->assertSame('unknown', $report['pages'][0]['search_appearance']);
        $this->assertSame([], $report['pages'][0]['current']['queries']);
        $this->assertFalse($report['pages'][0]['search_comparison_available']);
    }

    #[Test]
    public function partial_reads_preserve_the_last_success_and_disable_comparison(): void
    {
        $project = $this->project();
        $page = SitePage::factory()->create(['tracked_at' => now()]);
        $this->search()->willReadPages(new ReadResult(ReadStatus::Complete, [new SearchRow($page->url, '2026-08-20', 100, 5, 8.0)]));
        app(SynchronizePageMeasurements::class)->sync($project);
        $successful = PageMetric::query()->firstOrFail()->measurement_read_id;
        $this->search()->willReadPages(new ReadResult(ReadStatus::Partial, [new SearchRow($page->url, '2026-08-20', 4, 0, 10.0)], 'API stopped early.'));
        app(SynchronizePageMeasurements::class)->sync($project);
        $this->assertSame(100, PageMetric::query()->firstOrFail()->impressions);
        $report = app(PagePerformance::class)->forProject($project);
        $this->assertSame('partial', $report['sources']['gsc_pages']['status']);
        $this->assertSame($successful, $report['sources']['gsc_pages']['last_successful_read_id']);
        $this->assertTrue($report['sources']['gsc_pages']['stale']);
        $this->assertSame(100, $report['pages'][0]['current']['search']['impressions']);
        $this->assertFalse($report['pages'][0]['search_comparison_available']);
    }

    #[Test]
    public function a_successful_empty_restatement_removes_stale_observations_but_does_not_prove_zero(): void
    {
        $project = $this->project();
        $page = SitePage::factory()->create(['tracked_at' => now()]);
        $this->search()->willReadPages(new ReadResult(ReadStatus::Complete, [new SearchRow($page->url, '2026-08-20', 100, 5, 8.0)]));
        app(SynchronizePageMeasurements::class)->sync($project);
        $this->search()->willReadPages(new ReadResult(ReadStatus::Complete));
        app(SynchronizePageMeasurements::class)->sync($project);
        $this->assertSame(0, PageMetric::query()->count());
        $this->assertNull(app(PagePerformance::class)->forProject($project)['pages'][0]['current']['search']['impressions']);
    }

    #[Test]
    public function report_is_tenant_scoped_and_restores_the_previous_tenant(): void
    {
        $first = $this->project();
        $firstPage = SitePage::factory()->create(['url' => 'https://first.example/service', 'tracked_at' => now()]);
        $this->search()->willReadPages(new ReadResult(ReadStatus::Complete, [new SearchRow($firstPage->url, '2026-08-20', 55, 2, 9.0)]));
        app(SynchronizePageMeasurements::class)->sync($first);
        $second = $this->project();
        SitePage::factory()->create(['url' => 'https://second.example/service', 'tracked_at' => now()]);
        $report = app(PagePerformance::class)->forProject($first);
        $this->assertSame('https://first.example/service', $report['pages'][0]['url']);
        $this->assertSame($second->id, app(CurrentProject::class)->id());
        $this->assertSame(0, PageMetric::query()->count());
        $this->assertNull(app(PagePerformance::class)->forProject($second)['pages'][0]['current']['search']['impressions']);
    }

    #[Test]
    public function the_command_queues_only_active_projects_with_tracked_pages(): void
    {
        Queue::fake();
        $active = $this->project();
        SitePage::factory()->create(['tracked_at' => now()]);
        $paused = $this->project();
        $paused->update(['status' => ProjectStatus::Paused]);
        SitePage::factory()->create(['tracked_at' => now()]);
        $this->project();
        SitePage::factory()->create();
        $command = $this->artisan('pages:measure', []);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
        Queue::assertPushed(SyncPageMeasurementsJob::class, 1);
        Queue::assertPushed(SyncPageMeasurementsJob::class, fn (SyncPageMeasurementsJob $job): bool => $job->projectId === $active->id);
    }

    #[Test]
    public function job_failure_and_stale_reading_are_reported_honestly(): void
    {
        $project = $this->project();
        $job = new SyncPageMeasurementsJob($project->id);
        $record = MeasurementRead::query()->create([
            'source' => 'gsc_pages', 'window_from' => '2026-07-19', 'window_to' => '2026-09-12',
            'status' => ReadStatus::Reading, 'started_at' => now()->subHour(), 'metadata' => ['batch_id' => $job->batchId],
        ]);
        $this->assertSame('failed', app(PagePerformance::class)->forProject($project)['sources']['gsc_pages']['status']);
        $job->failed(null);
        $this->assertSame(ReadStatus::Failed, $record->refresh()->status);
        $this->assertNotNull($record->finished_at);
    }

    #[Test]
    public function analytics_selects_attested_alias_paths_as_property_evidence_without_assigning_a_page(): void
    {
        $project = $this->project();
        $canonical = 'https://example.com/service';
        $page = SitePage::factory()->create(['url' => $canonical, 'canonical_url' => $canonical, 'canonical_hash' => hash('sha256', $canonical), 'locale' => 'en', 'tracked_at' => now()]);
        foreach (['old-alias', 'latest-alias'] as $alias) {
            PageSnapshot::query()->create([
                'site_page_id' => $page->id, 'source_kind' => 'public', 'source_url' => 'https://example.com/'.$alias,
                'captured_at' => now(), 'revision' => $alias, 'content_hash' => hash('sha256', $alias), 'fields' => [], 'editable_fields' => [],
                'metadata' => ['canonical_url' => $canonical, 'locale' => 'en', 'requested_url' => 'https://example.com/request-'.$alias],
            ]);
        }
        $this->analytics()->willReadPurchases(new ReadResult(ReadStatus::Complete, [
            new AnalyticsRow('/latest-alias', '2026-08-20', 'Organic Search', 3, 1, 1000000, 0, 1000000, 'EUR'),
            new AnalyticsRow('/request-latest-alias', '2026-08-20', 'Organic Search', 2, 1, 1000000, 0, 1000000, 'EUR'),
            new AnalyticsRow('/old-alias', '2026-08-20', 'Organic Search', 100, 90, 90000000, 0, 90000000, 'EUR'),
            new AnalyticsRow('/guessed-alias', '2026-08-20', 'Organic Search', 100, 90, 90000000, 0, 90000000, 'EUR'),
        ]));
        app(SynchronizePageMeasurements::class)->sync($project);
        $this->assertSame(5, (int) PropertyAnalyticsMetric::query()->sum('sessions'));
        $this->assertSame(2, (int) PropertyAnalyticsMetric::query()->sum('purchases'));
        $this->assertSame(0, PageAnalyticsMetric::query()->count());
    }

    #[Test]
    public function property_paths_remain_unassigned_even_when_multiple_sites_share_the_path_and_partial_reads_preserve_history(): void
    {
        $project = $this->project();
        SitePage::factory()->create(['url' => 'https://first.example/service', 'tracked_at' => now()]);
        SitePage::factory()->create(['url' => 'https://second.example/service', 'tracked_at' => now()]);
        $this->analytics()->willReadPurchases(new ReadResult(ReadStatus::Complete, [
            new AnalyticsRow('/service', '2026-08-20', 'Organic Search', 100, 4, 4000000, 0, 4000000, 'EUR'),
        ]));
        app(SynchronizePageMeasurements::class)->sync($project);
        $this->analytics()->willReadPurchases(new ReadResult(ReadStatus::Partial, [], 'Report was sampled.'));
        app(SynchronizePageMeasurements::class)->sync($project);
        $report = app(PagePerformance::class)->forProject($project);
        $this->assertSame(4, $report['analytics']['current']['reported_purchases']);
        $this->assertSame('/service', $report['analytics']['paths'][0]['path']);
        $this->assertNull($report['analytics']['landing_origin']);
        $this->assertSame('partial', $report['sources']['ga4_property_landing_paths']['status']);
        $this->assertTrue($report['sources']['ga4_property_landing_paths']['stale']);
        $this->assertNull($report['pages'][0]['current']['analytics']['reported_purchases']);
        $this->assertNull($report['pages'][1]['current']['analytics']['reported_purchases']);
        $this->assertSame(0, PageAnalyticsMetric::query()->count());
    }

    private function search(): FakeSearchConsole
    {
        $gateway = app(SearchConsoleGateway::class);
        $this->assertInstanceOf(FakeSearchConsole::class, $gateway);

        return $gateway;
    }

    private function analytics(): FakeAnalytics
    {
        $gateway = app(AnalyticsGateway::class);
        $this->assertInstanceOf(FakeAnalytics::class, $gateway);

        return $gateway;
    }

    private function project(): Project
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        app(CurrentProject::class)->set($project);

        return $project;
    }
}
