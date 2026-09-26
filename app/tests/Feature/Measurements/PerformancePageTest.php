<?php

declare(strict_types=1);

namespace Tests\Feature\Measurements;

use App\Enums\ProjectStatus;
use App\Feedback\Measurements\ReadStatus;
use App\Feedback\Measurements\SyncPageMeasurementsJob;
use App\Feedback\Measurements\SyncSiteSearchJob;
use App\Models\MeasurementRead;
use App\Models\PageMetric;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\SitePage;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PerformancePageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owners_see_tracked_pages_and_unknown_measurements_until_real_data_exists(): void
    {
        [$owner] = $this->member('owner');
        $page = SitePage::factory()->create(['tracked_at' => now()]);
        SitePage::factory()->create();
        $this->actingAs($owner)->get('/performance')->assertOk()->assertInertia(fn (AssertableInertia $pageResponse) => $pageResponse
            ->component('performance/index')->has('report.pages', 1)
            ->where('report.pages.0.id', $page->id)
            ->where('report.pages.0.current.search.impressions', null)
            ->where('report.pages.0.current.analytics.reported_purchases', null)
            ->where('report.sources.gsc_pages.status', 'not_read')
            ->where('report.windows.current.days', 28)
            ->where('report.windows.previous.days', 28)
            ->where('search_connected', false)
            ->where('search_data_mode', 'none')
            ->missing('results')
            ->missing('articles'));
    }

    #[Test]
    public function a_connected_search_account_without_measurements_queues_only_the_site_read_and_no_page_read(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        [$owner] = $this->member('owner');
        SitePage::factory()->create(['tracked_at' => now()]);
        ProjectIntegration::factory()->searchOnly()->create();

        $this->actingAs($owner)->get('/performance')->assertOk()->assertInertia(fn (AssertableInertia $pageResponse) => $pageResponse
            ->where('search_connected', true)
            ->where('search_data_mode', 'current')
            ->where('report.sources.gsc_pages.status', 'not_read')
            ->where('report.pages.0.current.search.clicks', null)
            ->where('site_search.state', 'reading'));

        Http::assertNothingSent();
        // The whole property has never been read, so the page asks for it —
        // once, however often it polls. Tracked pages still wait for their
        // own read.
        $this->get('/performance')->assertOk();
        Queue::assertPushed(SyncSiteSearchJob::class, 1);
        Queue::assertNotPushed(SyncPageMeasurementsJob::class);
    }

    #[Test]
    public function only_an_owner_can_queue_a_measurement_and_duplicate_clicks_use_one_job(): void
    {
        Queue::fake();
        [$owner, $project] = $this->member('owner');
        SitePage::factory()->create(['tracked_at' => now()]);
        $this->actingAs($owner)->post('/performance/read')->assertRedirect('/performance');
        $this->post('/performance/read')->assertRedirect('/performance');
        Queue::assertPushed(SyncPageMeasurementsJob::class, 1);
        Queue::assertPushed(SyncPageMeasurementsJob::class, fn (SyncPageMeasurementsJob $job): bool => $job->projectId === $project->id && $job->queue === 'pipeline-expensive');
        // No Search Console connection: only the tracked pages are read.
        Queue::assertNotPushed(SyncSiteSearchJob::class);
        $this->post('/performance/read')->assertStatus(429);
    }

    #[Test]
    public function members_can_read_observations_but_cannot_trigger_provider_requests(): void
    {
        Queue::fake();
        [$member] = $this->member('viewer');
        SitePage::factory()->create(['tracked_at' => now()]);
        $this->actingAs($member)->get('/performance')->assertOk();
        $this->post('/performance/read')->assertForbidden();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_site_is_read_only_when_connected_and_nothing_readable_or_a_paused_project_is_refused(): void
    {
        Queue::fake();
        [$owner, $project] = $this->member('owner');
        // Neither a property nor a tracked page: nothing to queue.
        $this->actingAs($owner)->post('/performance/read')->assertStatus(422);
        Queue::assertNothingPushed();

        ProjectIntegration::factory()->searchOnly()->create();
        $this->travel(2)->minutes();
        $this->post('/performance/read')->assertRedirect('/performance');
        Queue::assertPushed(SyncSiteSearchJob::class, 1);
        Queue::assertNotPushed(SyncPageMeasurementsJob::class);

        $this->travel(2)->minutes();
        SitePage::factory()->create(['tracked_at' => now()]);
        $project->update(['status' => ProjectStatus::Paused]);
        $this->post('/performance/read')->assertStatus(422);
        Queue::assertPushed(SyncSiteSearchJob::class, 1);
        Queue::assertNotPushed(SyncPageMeasurementsJob::class);
    }

    #[Test]
    public function disconnected_accounts_keep_a_saved_zero_measurement_in_its_original_window(): void
    {
        [$owner] = $this->member('owner');
        $page = SitePage::factory()->create(['tracked_at' => now()]);
        $day = now('America/Los_Angeles')->subMonths(4)->toDateString();
        $read = MeasurementRead::query()->create([
            'source' => 'gsc_pages',
            'window_from' => now('America/Los_Angeles')->subMonths(4)->subDays(27)->toDateString(),
            'window_to' => $day,
            'status' => ReadStatus::Complete,
            'row_count' => 1,
            'started_at' => now()->subMonths(4),
            'finished_at' => now()->subMonths(4),
        ]);
        PageMetric::query()->create([
            'site_page_id' => $page->id,
            'measurement_read_id' => $read->id,
            'measured_on' => $day,
            'impressions' => 0,
            'clicks' => 0,
            'position_tenths' => null,
        ]);
        $untracked = SitePage::factory()->create();
        PageMetric::query()->create([
            'site_page_id' => $untracked->id,
            'measurement_read_id' => $read->id,
            'measured_on' => now('America/Los_Angeles')->subDays(4)->toDateString(),
            'impressions' => 30,
            'clicks' => 4,
            'position_tenths' => 80,
        ]);

        $this->actingAs($owner)->get('/performance')->assertOk()
            ->assertInertia(fn (AssertableInertia $response) => $response
                ->where('search_connected', false)
                ->where('search_data_mode', 'saved')
                ->where('report.windows.current.to', $day)
                ->where('report.pages.0.current.search.impressions', 0)
                ->where('report.pages.0.current.search.clicks', 0));
    }

    #[Test]
    public function connected_accounts_distinguish_recorded_zero_from_waiting_for_the_first_read(): void
    {
        [$owner] = $this->member('owner');
        $page = SitePage::factory()->create(['tracked_at' => now()]);
        ProjectIntegration::factory()->searchOnly()->create();
        $day = now('America/Los_Angeles')->subDays(4)->toDateString();
        $read = MeasurementRead::query()->create([
            'source' => 'gsc_pages',
            'window_from' => now('America/Los_Angeles')->subDays(31)->toDateString(),
            'window_to' => $day,
            'status' => ReadStatus::Complete,
            'row_count' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        PageMetric::query()->create([
            'site_page_id' => $page->id,
            'measurement_read_id' => $read->id,
            'measured_on' => $day,
            'impressions' => 0,
            'clicks' => 0,
            'position_tenths' => null,
        ]);

        $this->actingAs($owner)->get('/performance')->assertOk()
            ->assertInertia(fn (AssertableInertia $response) => $response
                ->where('search_connected', true)
                ->where('search_data_mode', 'current')
                ->where('report.pages.0.current.search.impressions', 0));
    }

    /** @return array{User, Project} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $user->projects()->attach($project, ['role' => $role]);
        app(CurrentProject::class)->set($project);

        return [$user, $project];
    }
}
