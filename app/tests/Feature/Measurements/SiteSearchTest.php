<?php

declare(strict_types=1);

namespace Tests\Feature\Measurements;

use App\Billing\Entitlements;
use App\Enums\OnboardingStatus;
use App\Enums\ProjectStatus;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\FakeSearchConsole;
use App\Feedback\GoogleSearchConsole;
use App\Feedback\ManagerResults;
use App\Feedback\Measurements\ReadResult;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SiteSearchReport;
use App\Feedback\Measurements\SiteSearchRow;
use App\Feedback\Measurements\SynchronizeSiteSearch;
use App\Feedback\Measurements\SyncPageMeasurementsJob;
use App\Feedback\Measurements\SyncSiteSearchJob;
use App\Feedback\Measurements\Windows;
use App\Models\ContentItem;
use App\Models\MeasurementRead;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\ProjectSubscription;
use App\Models\SitePage;
use App\Models\SiteSearchDay;
use App\Models\SiteSearchTopRow;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SiteSearchTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_FROM = '2026-08-16';

    private const PREVIOUS_FROM = '2026-07-19';

    protected function setUp(): void
    {
        parent::setUp();
        // Windows: current 2026-08-16..2026-09-12, previous 2026-07-19..2026-08-15,
        // history from 2025-05-13.
        $this->travelTo(Carbon::parse('2026-09-15 18:00:00', 'UTC'));
        config()->set('services.google', ['client_id' => 'id', 'client_secret' => 'secret', 'redirect' => 'http://localhost/callback']);
    }

    // The real adapter

    #[Test]
    public function the_daily_report_asks_for_sixteen_months_of_final_property_totals_with_no_page_filter(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        Http::fake(['www.googleapis.com/*' => Http::response(['rows' => [
            ['keys' => ['2026-09-01'], 'impressions' => 100, 'clicks' => 10, 'position' => 5.5],
        ]])]);
        $windows = new Windows;

        $result = app(GoogleSearchConsole::class)->siteReport($project, SynchronizeSiteSearch::historyFrom($windows), $windows->to, 'date');

        $this->assertSame(ReadStatus::Complete, $result->status);
        $this->assertEquals([new SiteSearchRow('2026-09-01', null, 100, 10, 5.5)], $result->rows);
        $this->assertSame('sc-domain:example.com', $result->metadata['property']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), rawurlencode('sc-domain:example.com'))
            && $request['dimensions'] === ['date'] && $request['aggregationType'] === 'byProperty'
            && $request['dataState'] === 'final' && $request['type'] === 'web'
            && $request['startDate'] === '2025-05-13' && $request['endDate'] === '2026-09-12'
            && ! isset($request['dimensionFilterGroups']));
    }

    #[Test]
    public function a_sync_through_the_real_adapter_asks_for_the_top_two_hundred_and_fifty_queries_and_pages_per_period(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        Http::fake(fn (Request $request) => Http::response(['rows' => [match ($request['dimensions']) {
            ['date'] => ['keys' => ['2026-09-01'], 'impressions' => 10, 'clicks' => 1],
            ['query'] => ['keys' => ['window cleaning'], 'impressions' => 10, 'clicks' => 1, 'position' => 3],
            default => ['keys' => ['https://example.com/services/'], 'impressions' => 10, 'clicks' => 1, 'position' => 3],
        }]]));
        $this->app->instance(SearchConsoleGateway::class, app(GoogleSearchConsole::class));

        $reads = app(SynchronizeSiteSearch::class)->sync($project);

        $this->assertSame([ReadStatus::Complete, ReadStatus::Complete, ReadStatus::Complete], array_values(array_map(static fn (MeasurementRead $read): ReadStatus => $read->status, $reads)));
        Http::assertSentCount(5);
        foreach (['query' => 'byProperty', 'page' => 'byPage'] as $dimension => $aggregation) {
            foreach ([[self::CURRENT_FROM, '2026-09-12'], [self::PREVIOUS_FROM, '2026-08-15']] as [$from, $to]) {
                Http::assertSent(fn (Request $request): bool => $request['dimensions'] === [$dimension]
                    && $request['aggregationType'] === $aggregation && $request['rowLimit'] === 250 && $request['startRow'] === 0
                    && $request['dataState'] === 'final' && $request['startDate'] === $from && $request['endDate'] === $to);
            }
        }
        $this->assertSame(1, SiteSearchDay::query()->count());
        $this->assertSame(4, SiteSearchTopRow::query()->count());
    }

    #[Test]
    public function a_refused_grant_marks_the_connection_broken_and_a_refused_property_does_not(): void
    {
        [, $project] = $this->owner();
        $integration = $this->connect($project);
        $windows = new Windows;
        Http::fake(['www.googleapis.com/*' => Http::sequence()->push([], 403)->push([], 401)]);

        $forbidden = app(GoogleSearchConsole::class)->siteReport($project, $windows->currentFrom, $windows->to, 'query', 250);
        $this->assertSame(ReadStatus::Unavailable, $forbidden->status);
        $this->assertTrue($integration->refresh()->isUsable());

        $refused = app(GoogleSearchConsole::class)->siteReport($project, $windows->currentFrom, $windows->to, 'query', 250);
        $this->assertSame(ReadStatus::Unavailable, $refused->status);
        $this->assertNotNull($integration->refresh()->failure_reason);
    }

    #[Test]
    public function malformed_rows_make_the_report_partial_but_rows_that_are_not_ours_are_only_skipped(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        $windows = new Windows;
        Http::fake(['www.googleapis.com/*' => Http::sequence()
            ->push(['rows' => [
                ['keys' => ['2026-09-01'], 'impressions' => 10, 'clicks' => 1],
                ['keys' => ['not-a-day'], 'impressions' => 10, 'clicks' => 1],
                ['keys' => ['2026-09-02'], 'impressions' => 'many', 'clicks' => 1],
            ]])
            ->push(['rows' => [
                ['keys' => ['android-app://com.example/path'], 'impressions' => 1, 'clicks' => 0],
                ['keys' => ['https://example.com/'], 'impressions' => 1, 'clicks' => 0],
            ]])
            ->push(['rows' => [['keys' => ['   '], 'impressions' => 1, 'clicks' => 0], ['keys' => ['cleaning'], 'impressions' => 1, 'clicks' => 0]]])
            ->push([], 500)]);

        $days = app(GoogleSearchConsole::class)->siteReport($project, $windows->from, $windows->to, 'date');
        $this->assertSame(ReadStatus::Partial, $days->status);
        $this->assertCount(1, $days->rows);
        $this->assertSame(2, $days->metadata['malformed_rows']);

        $pages = app(GoogleSearchConsole::class)->siteReport($project, $windows->from, $windows->to, 'page', 250);
        $this->assertSame(ReadStatus::Complete, $pages->status);
        $this->assertCount(1, $pages->rows);
        $this->assertSame(1, $pages->metadata['skipped_rows']);
        $this->assertSame(0, $pages->metadata['malformed_rows']);

        $queries = app(GoogleSearchConsole::class)->siteReport($project, $windows->from, $windows->to, 'query', 250);
        $this->assertSame(ReadStatus::Complete, $queries->status);
        $this->assertSame(1, $queries->metadata['skipped_rows']);

        $this->assertSame(ReadStatus::Failed, app(GoogleSearchConsole::class)->siteReport($project, $windows->from, $windows->to, 'date')->status);
    }

    #[Test]
    public function the_daily_report_pages_until_google_returns_a_short_page(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        config()->set('measurements.search_page_size', 1);
        $windows = new Windows;
        Http::fake(['www.googleapis.com/*' => Http::sequence()
            ->push(['rows' => [['keys' => ['2026-09-01'], 'impressions' => 10, 'clicks' => 1]]])
            ->push(['rows' => [['keys' => ['2026-09-02'], 'impressions' => 20, 'clicks' => 2]]])
            ->push([])]);

        $result = app(GoogleSearchConsole::class)->siteReport($project, $windows->from, $windows->to, 'date');

        $this->assertSame(ReadStatus::Complete, $result->status);
        $this->assertCount(2, $result->rows);
        $this->assertTrue($result->metadata['pagination_complete']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request['startRow'] === 2 && $request['rowLimit'] === 1);
    }

    // Sync and storage

    #[Test]
    public function a_sync_stores_history_and_both_periods_of_top_rows_without_any_tracked_page(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        $this->scriptSite();

        app(SynchronizeSiteSearch::class)->sync($project);
        $reads = app(SynchronizeSiteSearch::class)->sync($project);

        $this->assertSame(0, SitePage::query()->tracked()->count());
        $this->assertSame([SynchronizeSiteSearch::DAILY, SynchronizeSiteSearch::QUERIES, SynchronizeSiteSearch::PAGES], array_keys($reads));
        $this->assertSame(ReadStatus::Complete, $reads[SynchronizeSiteSearch::QUERIES]->status);
        $this->assertSame(3, $reads[SynchronizeSiteSearch::QUERIES]->row_count);
        $this->assertSame('2025-05-13', $reads[SynchronizeSiteSearch::DAILY]->window_from->toDateString());
        $this->assertSame(4, SiteSearchDay::query()->count());
        $this->assertSame(3, SiteSearchTopRow::query()->where('kind', 'query')->count());
        $this->assertSame(2, SiteSearchTopRow::query()->where('kind', 'page')->count());
        $this->assertContains(['dimension' => 'date', 'from' => '2025-05-13', 'to' => '2026-09-12', 'row_limit' => null, 'property' => 'sc-domain:example.com'], $this->search()->siteCalls);
        $this->assertContains(['dimension' => 'query', 'from' => self::PREVIOUS_FROM, 'to' => '2026-08-15', 'row_limit' => 250, 'property' => 'sc-domain:example.com'], $this->search()->siteCalls);
        $this->assertSame('sc-domain:example.com', $reads[SynchronizeSiteSearch::PAGES]->metadata['property']);

        $report = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('ready', $report['state']);
        $this->assertSame('sc-domain:example.com', $report['property']);
        $this->assertFalse($report['reading']);
        $this->assertNotNull($report['updated_at']);
        $this->assertSame(['from' => self::CURRENT_FROM, 'to' => '2026-09-12', 'days' => 28], $report['windows']['current']);
        $this->assertSame(30, $report['current']['clicks']);
        $this->assertSame(400, $report['current']['impressions']);
        $this->assertEqualsWithDelta(0.075, $report['current']['ctr'], 0.0001);
        // Impression-weighted: (100 × 5.0 + 300 × 7.0) / 400.
        $this->assertEqualsWithDelta(6.5, $report['current']['position'], 0.0001);
        $this->assertSame(['clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => null], $report['previous']);
        $this->assertSame('2025-06-01', $report['history_from']);
        $this->assertSame(['day' => '2025-06-01', 'clicks' => 1, 'impressions' => 10, 'position' => 20], $report['daily'][0]);
        $this->assertCount(4, $report['daily']);
        $this->assertSame('lisbon cleaners', $report['top_queries'][0]['query']);
        $this->assertNull($report['top_queries'][0]['previous']);
        $this->assertSame('window cleaning', $report['top_queries'][1]['query']);
        $this->assertSame(4, $report['top_queries'][1]['previous']['clicks']);
        $this->assertEqualsWithDelta(6.0, $report['top_queries'][1]['previous']['position'], 0.0001);
        $this->assertSame('https://example.com/services/', $report['top_pages'][0]['url']);
        $this->assertSame(['queries' => ['from' => self::CURRENT_FROM, 'to' => '2026-09-12'], 'pages' => ['from' => self::CURRENT_FROM, 'to' => '2026-09-12']], $report['top_windows']);
        $this->assertFalse($report['stale']);
        $this->assertSame('complete', $report['latest']['status']);
        $this->assertFalse($report['top_pages'][0]['tracked']);
        $this->assertNull($report['top_pages'][0]['page_id']);
    }

    #[Test]
    public function an_incomplete_or_failed_read_never_replaces_complete_rows(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);
        $complete = app(SiteSearchReport::class)->forProject($project)['updated_at'];

        $this->travel(1)->hour();
        $this->search()
            ->willReadSite('date', new ReadResult(ReadStatus::Partial, [new SiteSearchRow('2026-09-01', null, 4, 0, null)], 'API stopped early.'))
            ->willReadSite('query', new ReadResult(ReadStatus::Failed, reason: 'Search Console answered 500.'), self::PREVIOUS_FROM)
            ->willReadSite('page', new ReadResult(ReadStatus::Unavailable, reason: 'Search Console answered 403.'), self::CURRENT_FROM);
        $reads = app(SynchronizeSiteSearch::class)->sync($project);

        $this->assertSame(ReadStatus::Partial, $reads[SynchronizeSiteSearch::DAILY]->status);
        $this->assertSame('API stopped early.', $reads[SynchronizeSiteSearch::DAILY]->reason);
        $this->assertSame(ReadStatus::Failed, $reads[SynchronizeSiteSearch::QUERIES]->status);
        $this->assertSame('complete', $reads[SynchronizeSiteSearch::QUERIES]->metadata['periods']['current']['status']);
        $this->assertSame(ReadStatus::Unavailable, $reads[SynchronizeSiteSearch::PAGES]->status);
        $this->assertSame(100, SiteSearchDay::query()->where('measured_on', '2026-09-01')->sole()->impressions);
        $this->assertSame(4, SiteSearchDay::query()->count());
        $this->assertSame(3, SiteSearchTopRow::query()->where('kind', 'query')->count());
        $this->assertSame(2, SiteSearchTopRow::query()->where('kind', 'page')->count());
        $report = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('ready', $report['state']);
        $this->assertSame($complete, $report['updated_at']);
        $this->assertSame(30, $report['current']['clicks']);
    }

    #[Test]
    public function one_projects_site_rows_never_appear_in_anothers_report(): void
    {
        [, $first] = $this->owner();
        $this->connect($first);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($first);
        [, $second] = $this->owner();
        $this->connect($second);

        $report = app(SiteSearchReport::class)->forProject($second);

        $this->assertNotSame('ready', $report['state']);
        $this->assertNull($report['current']);
        $this->assertSame([], $report['daily']);
        $this->assertSame([], $report['top_queries']);
        $this->assertSame([], $report['top_pages']);
        $this->assertSame($second->id, app(CurrentProject::class)->id());
        $this->assertSame('ready', app(SiteSearchReport::class)->forProject($first)['state']);
        $this->assertSame(0, SiteSearchDay::query()->count());
    }

    #[Test]
    public function the_job_reads_any_project_that_is_not_paused_or_archived_including_a_trial(): void
    {
        [, $trial] = $this->owner();
        $trial->update(['onboarding_status' => OnboardingStatus::Launching]);
        ProjectSubscription::query()->where('project_id', $trial->id)->delete();
        ProjectSubscription::factory()->forProject($trial)->trialing()->create();
        $this->connect($trial);
        (new SyncSiteSearchJob($trial->id))->handle(app(SynchronizeSiteSearch::class));
        $this->assertSame(3, MeasurementRead::query()->count());

        foreach ([['status' => ProjectStatus::Paused], ['archived_at' => now()]] as $state) {
            [, $project] = $this->owner();
            $this->connect($project);
            $project->forceFill($state)->save();
            (new SyncSiteSearchJob($project->id))->handle(app(SynchronizeSiteSearch::class));
            $this->assertSame(0, MeasurementRead::query()->count());
        }
    }

    #[Test]
    public function a_failed_job_reports_its_unfinished_reads_as_failed(): void
    {
        [, $project] = $this->owner();
        $job = new SyncSiteSearchJob($project->id);
        $this->assertSame('pipeline-expensive', $job->queue);
        $record = MeasurementRead::query()->create([
            'source' => SynchronizeSiteSearch::DAILY, 'window_from' => '2025-05-13', 'window_to' => '2026-09-12',
            'status' => ReadStatus::Reading, 'started_at' => now(), 'metadata' => ['batch_id' => $job->batchId],
        ]);
        $job->failed(null);
        $this->assertSame(ReadStatus::Failed, $record->refresh()->status);
        $this->assertNotNull($record->finished_at);
    }

    // States

    #[Test]
    public function the_report_names_why_there_is_nothing_to_show(): void
    {
        Queue::fake();
        [, $project] = $this->owner();
        $this->assertSame('not_connected', app(SiteSearchReport::class)->forProject($project)['state']);

        $integration = app(CurrentProject::class)->run($project, fn () => ProjectIntegration::factory()->unchosen()->create());
        $report = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('no_property', $report['state']);
        $this->assertNull($report['property']);

        $integration->forceFill(['config' => ['search_console_site' => 'sc-domain:example.com']])->save();
        // Nothing asked for and nothing recorded: not "reading" forever.
        $report = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('failed', $report['state']);
        $this->assertSame('Google has not answered yet. Try refreshing search data.', $report['reason']);

        $this->assertTrue(SyncSiteSearchJob::request($project));
        $report = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('reading', $report['state']);
        $this->assertFalse($report['reading']);

        // Asked for, never picked up.
        $this->travel(36)->minutes();
        $this->assertSame('failed', app(SiteSearchReport::class)->forProject($project)['state']);

        $read = $this->read(ReadStatus::Reading);
        $report = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('reading', $report['state']);
        $this->assertTrue($report['reading']);

        $this->travel(36)->minutes();
        $report = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('failed', $report['state']);
        $this->assertFalse($report['reading']);
        $this->assertStringContainsString('did not finish', (string) $report['reason']);

        $read->update(['status' => ReadStatus::Unavailable, 'reason' => 'Search Console answered 403.', 'finished_at' => now()]);
        $report = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('failed', $report['state']);
        $this->assertSame('Search Console answered 403.', $report['reason']);

        $this->read(ReadStatus::Complete);
        $this->assertSame('no_data', app(SiteSearchReport::class)->forProject($project)['state']);

        $project->update(['status' => ProjectStatus::Paused]);
        $report = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('paused', $report['state']);
        $this->assertSame('This project is paused, so search data is not being read.', $report['reason']);
        $project->update(['status' => ProjectStatus::Active]);

        // A read of another property is not a read of this one.
        $integration->forceFill(['config' => ['search_console_site' => 'https://example.com/']])->save();
        $this->assertSame('failed', app(SiteSearchReport::class)->forProject($project)['state']);

        $integration->markBroken('Google no longer accepts this connection.');
        $this->assertSame('not_connected', app(SiteSearchReport::class)->forProject($project)['state']);
    }

    #[Test]
    public function the_window_is_the_one_the_stored_read_covered_not_todays(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        $read = $this->read(ReadStatus::Complete);
        for ($day = Carbon::parse(self::CURRENT_FROM); $day->lte(Carbon::parse('2026-09-12')); $day->addDay()) {
            SiteSearchDay::query()->create(['measurement_read_id' => $read->id, 'measured_on' => $day->toDateString(), 'clicks' => 1, 'impressions' => 10]);
        }

        $this->travel(2)->days();
        $report = app(SiteSearchReport::class)->forProject($project);

        $this->assertSame(['from' => self::CURRENT_FROM, 'to' => '2026-09-12', 'days' => 28], $report['windows']['current']);
        $this->assertSame(28, $report['current']['clicks']);
        // Two days behind is the ordinary lag of a daily read, not staleness.
        $this->assertFalse($report['stale']);
        $this->assertSame(['status' => 'complete', 'reason' => null, 'finished_at' => $read->finished_at?->toIso8601String()], $report['latest']);
        $search = app(ManagerResults::class)->for($project)['search'];
        $this->assertSame('2026-09-12', $search['to']);
        $this->assertSame(28, array_sum(array_column($search['daily'], 'observed_pages')));
        $this->assertFalse($search['stale']);

        $this->travel(1)->day();
        $this->assertTrue(app(SiteSearchReport::class)->forProject($project)['stale']);
        $this->assertTrue(app(ManagerResults::class)->for($project)['search']['stale']);
        $this->assertSame('2026-09-12', app(SiteSearchReport::class)->forProject($project)['windows']['current']['to']);
    }

    #[Test]
    public function a_failed_attempt_is_stale_but_a_read_in_flight_is_not(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);
        $fresh = app(SiteSearchReport::class)->forProject($project);
        $this->assertFalse($fresh['stale']);

        $reading = $this->read(ReadStatus::Reading);
        $inFlight = app(SiteSearchReport::class)->forProject($project);
        $this->assertFalse($inFlight['stale']);
        $this->assertSame('reading', $inFlight['latest']['status']);
        $this->assertFalse(app(ManagerResults::class)->for($project)['search']['stale']);
        $this->assertSame('reading', app(ManagerResults::class)->for($project)['search']['status']);

        $reading->update(['status' => ReadStatus::Failed, 'reason' => 'Search Console answered 500.', 'finished_at' => now()]);
        $failed = app(SiteSearchReport::class)->forProject($project);
        $this->assertSame('ready', $failed['state']);
        $this->assertTrue($failed['stale']);
        $this->assertSame('failed', $failed['latest']['status']);
        $this->assertSame('Search Console answered 500.', $failed['latest']['reason']);
        $this->assertTrue(app(ManagerResults::class)->for($project)['search']['stale']);
    }

    #[Test]
    public function a_retry_after_a_failure_reads_as_reading_until_it_answers(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $this->connect($project);
        $this->read(ReadStatus::Failed)->update(['reason' => 'Search Console answered 500.']);
        $this->actingAs($owner)->get('/performance')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('site_search.state', 'failed')->where('site_search.reason', 'Search Console answered 500.'));

        $this->travel(1)->minute();
        $this->post('/performance/read')->assertRedirect('/performance');

        $this->get('/performance')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('site_search.state', 'reading')
            ->where('site_search.latest.status', 'failed'));

        // The retry answered, and failed again: that is the news now.
        $this->travel(1)->minute();
        $this->read(ReadStatus::Failed)->update(['reason' => 'Search Console answered 503.']);
        $this->get('/performance')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('site_search.state', 'failed')->where('site_search.reason', 'Search Console answered 503.'));
    }

    #[Test]
    public function switching_property_through_the_settings_during_a_read_leaves_nothing_behind(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $this->connect($project);
        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
                ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'],
                ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
            ]]),
            'analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => []]),
        ]);
        $gateway = new class extends FakeSearchConsole
        {
            public ?\Closure $during = null;

            public function siteReport(Project $project, Carbon $from, Carbon $to, string $dimension, ?int $rowLimit = null, ?string $property = null): ReadResult
            {
                if ($this->during !== null) {
                    ($this->during)();
                    $this->during = null;
                }

                return parent::siteReport($project, $from, $to, $dimension, $rowLimit, $property);
            }
        };
        $gateway->willReadSite('date', new ReadResult(ReadStatus::Complete, [new SiteSearchRow('2026-09-01', null, 100, 10, 5.0)]));
        $gateway->during = fn () => $this->actingAs($owner)->patch("/projects/{$project->id}/google", ['search_console_site' => 'https://example.com/'])->assertRedirect();
        $this->app->instance(SearchConsoleGateway::class, $gateway);

        $reads = app(SynchronizeSiteSearch::class)->sync($project);

        $this->assertSame([], $reads);
        // One request to Google, then it stopped: nothing left behind for the
        // old property, and nothing stored under the new one.
        $this->assertCount(1, $gateway->siteCalls);
        $this->assertSame(0, MeasurementRead::query()->count());
        $this->assertSame(0, SiteSearchDay::query()->count());
        $this->get('/performance')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('site_search.property', 'https://example.com/')
            ->where('site_search.state', 'reading')
            ->where('site_search.reason', null)
            ->where('site_search.latest', null));
        Queue::assertPushed(SyncSiteSearchJob::class);
    }

    #[Test]
    public function a_property_switch_during_a_read_stores_nothing_and_frees_the_self_heal(): void
    {
        Queue::fake();
        [, $project] = $this->owner();
        $integration = $this->connect($project);
        SyncSiteSearchJob::request($project);
        $gateway = new class extends FakeSearchConsole
        {
            public ?\Closure $during = null;

            public function siteReport(Project $project, Carbon $from, Carbon $to, string $dimension, ?int $rowLimit = null, ?string $property = null): ReadResult
            {
                if ($this->during !== null) {
                    ($this->during)();
                    $this->during = null;
                }

                return parent::siteReport($project, $from, $to, $dimension, $rowLimit, $property);
            }
        };
        $gateway->willReadSite('date', new ReadResult(ReadStatus::Complete, [new SiteSearchRow('2026-09-01', null, 100, 10, 5.0)]));
        $gateway->during = fn () => $integration->forceFill(['config' => ['search_console_site' => 'https://example.com/']])->save();
        $this->app->instance(SearchConsoleGateway::class, $gateway);

        $reads = app(SynchronizeSiteSearch::class)->sync($project);

        foreach ($reads as $read) {
            $this->assertSame(ReadStatus::Failed, $read->status);
            $this->assertSame('The property changed during the read.', $read->reason);
            $this->assertSame('sc-domain:example.com', $read->metadata['property']);
        }
        // Every call of the sync used the property it started with.
        $this->assertSame(['sc-domain:example.com'], array_values(array_unique(array_column($gateway->siteCalls, 'property'))));
        $this->assertSame(0, SiteSearchDay::query()->count());
        $this->assertNull(SyncSiteSearchJob::requestedAt($project->id));
        $this->assertTrue(app(SiteSearchReport::class)->needsFirstRead($project));
    }

    #[Test]
    public function a_refresh_in_flight_keeps_the_stored_report_ready(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);
        $this->read(ReadStatus::Reading);

        $report = app(SiteSearchReport::class)->forProject($project);

        $this->assertSame('ready', $report['state']);
        $this->assertTrue($report['reading']);
    }

    #[Test]
    public function top_pages_say_which_ones_are_already_monitored(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        $page = SitePage::factory()->notAnArticle()->create(['url' => 'https://example.com/services', 'canonical_url' => 'https://example.com/services/', 'tracked_at' => now()]);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);

        $pages = app(SiteSearchReport::class)->forProject($project)['top_pages'];

        $this->assertTrue($pages[0]['tracked']);
        $this->assertSame($page->id, $pages[0]['page_id']);
        $this->assertFalse($pages[1]['tracked']);
        $this->assertNull($pages[1]['previous']);
    }

    // Triggers

    #[Test]
    public function the_performance_page_queues_a_first_read_once_and_carries_the_report(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $this->actingAs($owner)->get('/performance')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('site_search.state', 'not_connected'));
        Queue::assertNotPushed(SyncSiteSearchJob::class);

        $this->connect($project);
        $this->get('/performance')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('site_search.state', 'reading')
            ->where('site_search.property', 'sc-domain:example.com')
            ->where('site_search.windows.current.days', 28)
            ->where('site_search.windows.previous.from', self::PREVIOUS_FROM)
            ->where('site_search.current', null)
            ->where('site_search.daily', [])
            ->has('site_search.top_queries', 0)
            ->has('site_search.top_pages', 0)
            ->where('site_search.top_windows', ['queries' => null, 'pages' => null])
            ->where('site_search.latest', null)
            ->where('site_search.stale', false)
            ->has('site_search.history_from')
            ->has('site_search.updated_at'));
        Queue::assertPushed(SyncSiteSearchJob::class, fn (SyncSiteSearchJob $job): bool => $job->projectId === $project->id);

        // Polling does not queue another; an unanswered request becomes a
        // failure the owner can act on instead of an endless "reading".
        Queue::fake();
        $this->get('/performance')->assertOk();
        $this->travel(36)->minutes();
        $this->get('/performance')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('site_search.state', 'failed')
            ->where('site_search.reason', 'Google has not answered yet. Try refreshing search data.'));
        Queue::assertNotPushed(SyncSiteSearchJob::class);

        // Refreshing asks again.
        $this->post('/performance/read')->assertRedirect('/performance');
        Queue::assertPushed(SyncSiteSearchJob::class, 1);
        $this->get('/performance')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('site_search.state', 'reading'));
    }

    #[Test]
    public function the_home_screen_queues_the_first_read_for_a_project_connected_before_site_reads(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $this->connect($project);

        // Home is where the owner lands, so it cannot wait for somebody to open
        // Search performance before the first read is asked for.
        $this->actingAs($owner)->get('/home')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.search.state', 'reading'));
        Queue::assertPushed(SyncSiteSearchJob::class, fn (SyncSiteSearchJob $job): bool => $job->projectId === $project->id);

        Queue::fake();
        $this->get('/home')->assertOk();
        Queue::assertNotPushed(SyncSiteSearchJob::class);
    }

    #[Test]
    public function a_paused_project_is_not_read_from_the_performance_page(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $this->connect($project);
        $project->update(['status' => ProjectStatus::Paused]);
        $this->actingAs($owner)->get('/performance')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('site_search.state', 'paused'));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_daily_command_reads_every_connected_property_even_with_no_tracked_pages(): void
    {
        Queue::fake();
        [, $connected] = $this->owner();
        $this->connect($connected);
        [, $unconnected] = $this->owner();
        app(CurrentProject::class)->run($unconnected, fn () => SitePage::factory()->create(['tracked_at' => now()]));
        [, $paused] = $this->owner();
        $this->connect($paused);
        $paused->update(['status' => ProjectStatus::Paused]);

        $command = $this->artisan('pages:measure');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();

        Queue::assertPushed(SyncSiteSearchJob::class, 1);
        Queue::assertPushed(SyncSiteSearchJob::class, fn (SyncSiteSearchJob $job): bool => $job->projectId === $connected->id);
        Queue::assertPushed(SyncPageMeasurementsJob::class, 1);
        Queue::assertPushed(SyncPageMeasurementsJob::class, fn (SyncPageMeasurementsJob $job): bool => $job->projectId === $unconnected->id);
    }

    #[Test]
    public function the_daily_command_can_read_the_property_inline(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);

        $command = $this->artisan('pages:measure', ['--sync' => true, '--project' => $project->slug]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutputToContain('gsc_site_daily: complete')
            ->expectsOutputToContain('gsc_site_pages: complete')
            ->assertSuccessful()->run();

        $this->assertSame(3, MeasurementRead::query()->where('status', ReadStatus::Complete)->count());
    }

    // Monitoring a top page

    #[Test]
    public function an_owner_can_monitor_one_of_the_top_pages(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $this->connect($project);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);
        $this->fakePublicPage();

        $this->actingAs($owner)->post('/performance/monitor', ['url' => 'https://example.com/services/'])
            ->assertSessionHasNoErrors()->assertRedirect('/performance');

        $page = SitePage::query()->tracked()->sole();
        $this->assertSame('https://example.com/services/', $page->canonical_url);
        $this->assertSame('en', $page->locale);
        $this->assertSame('Page added to monitored pages.', session(SessionKey::FLASH_DATA)['toast']['message']);
        Queue::assertPushed(SyncPageMeasurementsJob::class, fn (SyncPageMeasurementsJob $job): bool => $job->projectId === $project->id);
        $this->assertTrue(app(SiteSearchReport::class)->forProject($project)['top_pages'][0]['tracked']);
    }

    #[Test]
    public function only_a_url_search_console_reported_can_be_monitored(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $this->connect($project);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);
        // Another project's top page is not this project's.
        [, $other] = $this->owner();
        app(CurrentProject::class)->set($project);
        $this->connect($other);
        $this->search()->willReadSite('page', new ReadResult(ReadStatus::Complete, [new SiteSearchRow(null, 'https://other.example/', 5, 1, 2.0)]), self::CURRENT_FROM);
        app(SynchronizeSiteSearch::class)->sync($other);
        app(CurrentProject::class)->run($other, function (): void {
            $this->assertSame(['https://other.example/'], SiteSearchTopRow::query()->where('kind', 'page')->pluck('value')->all());
        });
        $this->assertNotContains('https://other.example/', SiteSearchTopRow::query()->pluck('value')->all());
        Http::fake();

        foreach (['https://example.com/elsewhere', 'https://other.example/', 'not a url'] as $url) {
            $this->actingAs($owner)->withSession(['project_id' => $project->id])
                ->post('/performance/monitor', ['url' => $url])->assertSessionHasErrors('url');
        }

        Http::assertNothingSent();
        $this->assertSame(0, SitePage::query()->tracked()->count());
        Queue::assertNotPushed(SyncPageMeasurementsJob::class);
    }

    #[Test]
    public function members_cannot_monitor_and_the_plan_limit_still_applies(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $viewer = User::factory()->create();
        $viewer->projects()->attach($project, ['role' => 'viewer']);
        $this->connect($project);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);
        $this->fakePublicPage();

        $this->actingAs($viewer)->post('/performance/monitor', ['url' => 'https://example.com/services/'])->assertForbidden();

        SitePage::factory()->notAnArticle()->create(['url' => 'https://example.com/contact', 'tracked_at' => now()]);
        ProjectSubscription::query()->where('project_id', $project->id)->update(['limit_overrides' => json_encode(['tracked_pages' => 1])]);
        app(Entitlements::class)->forget($project);
        $this->actingAs($owner)->post('/performance/monitor', ['url' => 'https://example.com/services/'])
            ->assertSessionHasErrors(['url' => 'This plan tracks up to 1 existing pages, plus articles published with Avyo. Pause another page before adding this one. Existing history stays available.']);

        $this->assertSame(1, SitePage::query()->tracked()->count());
        Queue::assertNotPushed(SyncPageMeasurementsJob::class);
    }

    #[Test]
    public function a_monitored_page_takes_its_language_from_its_path(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $project->update(['default_locale' => 'en', 'locales' => ['en', 'pt-PT']]);
        $this->connect($project);
        $this->search()->willReadSite('page', new ReadResult(ReadStatus::Complete, [
            new SiteSearchRow(null, 'https://example.com/PT/servicos/', 40, 3, 4.0),
        ]), self::CURRENT_FROM);
        app(SynchronizeSiteSearch::class)->sync($project);
        $this->fakePublicPage('https://example.com/PT/servicos/', 'pt');

        $this->actingAs($owner)->post('/performance/monitor', ['url' => 'https://example.com/PT/servicos/'])
            ->assertSessionHasNoErrors()->assertRedirect('/performance');

        $this->assertSame('pt-PT', SitePage::query()->tracked()->sole()->locale);
    }

    #[Test]
    public function a_page_with_no_language_in_its_path_takes_the_language_it_declares(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $project->update(['default_locale' => 'en', 'locales' => ['en', 'pt-PT']]);
        $this->connect($project);
        $this->search()->willReadSite('page', new ReadResult(ReadStatus::Complete, [
            new SiteSearchRow(null, 'https://example.com/servicos/', 40, 3, 4.0),
        ]), self::CURRENT_FROM);
        app(SynchronizeSiteSearch::class)->sync($project);
        $this->fakePublicPage('https://example.com/servicos/', 'pt-PT');

        $this->actingAs($owner)->post('/performance/monitor', ['url' => 'https://example.com/servicos/'])
            ->assertSessionHasNoErrors()->assertRedirect('/performance');

        $this->assertSame('pt-PT', SitePage::query()->tracked()->sole()->locale);
    }

    #[Test]
    public function a_path_language_shared_by_two_locales_is_settled_by_the_declared_one(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $project->update(['default_locale' => 'en', 'locales' => ['en', 'pt-PT', 'pt-BR']]);
        $this->connect($project);
        $this->search()->willReadSite('page', new ReadResult(ReadStatus::Complete, [
            new SiteSearchRow(null, 'https://example.com/pt/servicos/', 40, 3, 4.0),
        ]), self::CURRENT_FROM);
        app(SynchronizeSiteSearch::class)->sync($project);
        $this->fakePublicPage('https://example.com/pt/servicos/', 'pt-BR');

        $this->actingAs($owner)->post('/performance/monitor', ['url' => 'https://example.com/pt/servicos/'])
            ->assertSessionHasNoErrors()->assertRedirect('/performance');

        $this->assertSame('pt-BR', SitePage::query()->tracked()->sole()->locale);
    }

    #[Test]
    public function every_monitoring_refusal_is_reported_on_the_url_field(): void
    {
        Queue::fake();
        [$owner, $project] = $this->owner();
        $this->connect($project);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);
        $this->fakePublicPage();
        // A generated article in another language already owns this URL, which
        // tracking refuses on its `locale` key.
        ContentItem::factory()->published()->create(['public_url' => 'https://example.com/services/', 'locale' => 'pt']);

        $this->actingAs($owner)->post('/performance/monitor', ['url' => 'https://example.com/services/'])
            ->assertSessionHasErrors(['url' => 'The existing generated article uses another language.'])
            ->assertSessionDoesntHaveErrors('locale');

        $project->update(['status' => ProjectStatus::Paused]);
        $this->post('/performance/monitor', ['url' => 'https://example.com/services/'])
            ->assertSessionHasErrors(['url' => 'This project is paused. Resume it before monitoring more pages.']);
        $this->assertSame(0, SitePage::query()->tracked()->count());
        Queue::assertNotPushed(SyncPageMeasurementsJob::class);
    }

    // The Home card

    #[Test]
    public function the_home_card_shows_the_whole_property_once_it_has_been_read(): void
    {
        [, $project] = $this->owner();
        $this->connect($project);
        SitePage::factory()->create(['tracked_at' => now()]);
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);

        $search = app(ManagerResults::class)->for($project)['search'];

        $this->assertSame('site', $search['scope']);
        $this->assertSame('ready', $search['state']);
        $this->assertNull($search['reason']);
        $this->assertSame(30, $search['clicks']);
        $this->assertSame(400, $search['impressions']);
        $this->assertSame(5, $search['previous_clicks']);
        $this->assertSame(50, $search['previous_impressions']);
        $this->assertSame(1, $search['tracked_pages']);
        $this->assertSame(2, $search['observed_pages']);
        $this->assertSame('complete', $search['status']);
        $this->assertFalse($search['stale']);
        $this->assertTrue($search['connected']);
        $this->assertNotNull($search['updated_at']);
        $this->assertSame(self::CURRENT_FROM, $search['from']);
        $daily = array_column($search['daily'], null, 'day');
        $this->assertCount(28, $daily);
        $this->assertSame(['day' => '2026-09-01', 'clicks' => 10, 'impressions' => 100, 'observed_pages' => 1], $daily['2026-09-01']);
        $this->assertSame(['day' => '2026-09-03', 'clicks' => null, 'impressions' => null, 'observed_pages' => 0], $daily['2026-09-03']);
    }

    #[Test]
    public function the_home_card_is_about_the_site_once_connected_and_falls_back_only_without_a_connection(): void
    {
        [, $project] = $this->owner();
        $search = app(ManagerResults::class)->for($project)['search'];
        $this->assertSame('tracked_pages', $search['scope']);
        $this->assertSame('not_connected', $search['state']);
        $this->assertNull($search['reason']);
        $this->assertNull($search['clicks']);
        $this->assertSame('not_read', $search['status']);

        $this->connect($project);
        $this->read(ReadStatus::Failed)->update(['reason' => 'Search Console answered 500.']);
        $search = app(ManagerResults::class)->for($project)['search'];
        $this->assertSame('site', $search['scope']);
        $this->assertSame('failed', $search['state']);
        $this->assertSame('Search Console answered 500.', $search['reason']);
        $this->assertSame('failed', $search['status']);
        $this->assertNull($search['clicks']);
        $this->assertCount(28, $search['daily']);

        // Stored numbers are not shown under a paused project.
        $this->scriptSite();
        app(SynchronizeSiteSearch::class)->sync($project);
        $project->update(['status' => ProjectStatus::Paused]);
        $search = app(ManagerResults::class)->for($project)['search'];
        $this->assertSame('site', $search['scope']);
        $this->assertSame('paused', $search['state']);
        $this->assertSame('This project is paused, so search data is not being read.', $search['reason']);
        $this->assertNull($search['clicks']);
        $this->assertNull($search['previous_clicks']);
        $this->assertSame(0, array_sum(array_column($search['daily'], 'observed_pages')));
    }

    private function scriptSite(): void
    {
        $this->search()
            ->willReadSite('date', new ReadResult(ReadStatus::Complete, [
                new SiteSearchRow('2026-09-01', null, 100, 10, 5.0),
                new SiteSearchRow('2026-09-02', null, 300, 20, 7.0),
                new SiteSearchRow('2026-08-10', null, 50, 5, null),
                new SiteSearchRow('2025-06-01', null, 10, 1, 20.0),
            ]))
            ->willReadSite('query', new ReadResult(ReadStatus::Complete, [
                new SiteSearchRow(null, 'window cleaning', 200, 15, 4.0),
                new SiteSearchRow(null, 'lisbon cleaners', 100, 20, 2.0),
            ]), self::CURRENT_FROM)
            ->willReadSite('query', new ReadResult(ReadStatus::Complete, [
                new SiteSearchRow(null, 'window cleaning', 80, 4, 6.0),
            ]), self::PREVIOUS_FROM)
            ->willReadSite('page', new ReadResult(ReadStatus::Complete, [
                new SiteSearchRow(null, 'https://example.com/services/', 400, 30, 3.0),
                new SiteSearchRow(null, 'https://example.com/blog', 10, 0, null),
            ]), self::CURRENT_FROM)
            ->willReadSite('page', new ReadResult(ReadStatus::Complete), self::PREVIOUS_FROM);
    }

    private function read(ReadStatus $status): MeasurementRead
    {
        return MeasurementRead::query()->create([
            'source' => SynchronizeSiteSearch::DAILY, 'window_from' => '2025-05-13', 'window_to' => '2026-09-12',
            'status' => $status, 'started_at' => now(),
            'finished_at' => $status === ReadStatus::Reading ? null : now(),
            'metadata' => ['property' => 'sc-domain:example.com'],
        ]);
    }

    private function fakePublicPage(string $canonical = 'https://example.com/services/', string $lang = 'en'): void
    {
        Http::fake(['example.com/*' => Http::response(
            '<html lang="'.$lang.'"><head><title>Services</title><link rel="canonical" href="'.$canonical.'"></head><body><main><p>Our cleaning services.</p></main></body></html>',
            200, ['Content-Type' => 'text/html'],
        )]);
    }

    private function search(): FakeSearchConsole
    {
        $gateway = app(SearchConsoleGateway::class);
        $this->assertInstanceOf(FakeSearchConsole::class, $gateway);

        return $gateway;
    }

    private function connect(Project $project): ProjectIntegration
    {
        return app(CurrentProject::class)->run($project, fn () => ProjectIntegration::factory()->create());
    }

    /** @return array{User, Project} */
    private function owner(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['website_url' => 'https://example.com', 'default_locale' => 'en', 'locales' => ['en']]);
        $owner->projects()->attach($project, ['role' => 'owner']);
        app(CurrentProject::class)->set($project);

        return [$owner, $project];
    }
}
