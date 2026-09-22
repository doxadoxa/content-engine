<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Enums\OnboardingStatus;
use App\Feedback\ManagerResults;
use App\Feedback\Measurements\ReadStatus;
use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Models\LlmPrompt;
use App\Models\LlmVisibilityAnswer;
use App\Models\MeasurementRead;
use App\Models\PageMetric;
use App\Models\Project;
use App\Models\PurchaseSource;
use App\Models\SitePage;
use App\Models\User;
use App\Purchases\PurchaseIntake;
use App\Purchases\PurchasePayload;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DashboardResultsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-17 12:00:00', 'UTC'));
        Queue::fake();
        Http::fake();
        $this->project = Project::factory()->create(['onboarding_status' => OnboardingStatus::Active]);
        $this->owner = User::factory()->create();
        $this->project->users()->attach($this->owner, ['role' => 'owner']);
        app(CurrentProject::class)->set($this->project);
        $this->actingAs($this->owner)->withSession(['project_id' => $this->project->id]);
    }

    #[Test]
    public function dashboard_delivers_existing_visibility_on_first_paint_without_requesting_new_answers(): void
    {
        $prompt = LlmPrompt::factory()->for($this->project)->create(['is_active' => true]);
        foreach ([true, false] as $index => $mentioned) {
            LlmVisibilityAnswer::factory()->create(['llm_prompt_id' => $prompt->id, 'platform' => $index === 0 ? 'chat_gpt' : 'claude', 'mentioned' => $mentioned, 'asked_on' => '2026-09-10']);
        }
        $this->get('/home')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.earlier_ai.score', 50)->where('results.earlier_ai.answers', 2)
            ->where('results.ai.score', null)->where('results.search.clicks', null)
            ->where('results.purchases.count', null));
        $this->get('/visibility')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('legacy.score', 50)->where('legacy.mentions', 1)->has('legacy.providers', 2));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function discovery_counts_exclude_branded_questions_missing_answers_and_other_projects(): void
    {
        $run = $this->sampleRun();
        $this->cell($run, 'chat_gpt', true);
        $this->cell($run, 'chat_gpt', false);
        $this->cell($run, 'claude', null);
        $this->cell($run, 'gemini', true, 'accuracy');
        $other = Project::factory()->create();
        app(CurrentProject::class)->run($other, function () use ($other): void {
            $this->cell($this->sampleRun(), 'chat_gpt', true);
            $prompt = LlmPrompt::factory()->for($other)->create();
            LlmVisibilityAnswer::factory()->create(['llm_prompt_id' => $prompt->id, 'mentioned' => true, 'asked_on' => '2026-09-16']);
        });
        $results = app(ManagerResults::class)->for($this->project);
        $this->assertSame(2, $results['ai']['answers']);
        $this->assertSame(3, $results['ai']['expected']);
        $this->assertSame(1, $results['ai']['mentions']);
        $this->assertSame(50.0, $results['ai']['score']);
        $this->assertSame(50.0, array_column($results['ai']['providers'], null, 'platform')['chat_gpt']['score']);
        $this->assertNull(array_column($results['ai']['providers'], null, 'platform')['claude']['score']);
        $this->assertNull($results['earlier_ai']);
    }

    #[Test]
    public function empty_or_accuracy_runs_and_single_question_rechecks_do_not_erase_the_last_discovery_panel(): void
    {
        $run = $this->sampleRun();
        $this->cell($run, 'chat_gpt', false);
        $this->travel(1)->minute();
        $pending = $this->sampleRun();
        $pending->update(['status' => 'queued', 'finished_at' => null]);
        $this->cell($pending, 'chat_gpt', null);
        $this->travel(1)->minute();
        $this->cell($this->sampleRun(), 'gemini', true, 'accuracy');
        $this->travel(1)->minute();
        $recheck = $this->sampleRun();
        $recheck->update(['recheck_of_run_id' => $run->id]);
        $this->cell($recheck, 'chat_gpt', true);
        $results = app(ManagerResults::class)->for($this->project);
        $this->assertSame($run->id, $results['ai']['run_id']);
        $this->assertSame(0.0, $results['ai']['score']);
        $this->assertSame('queued', $results['ai']['latest_attempt']['status']);
    }

    #[Test]
    public function traffic_totals_and_chart_share_the_same_window_and_keep_missing_days_as_gaps(): void
    {
        $page = SitePage::factory()->create(['tracked_at' => now()]);
        $otherPage = SitePage::factory()->create(['tracked_at' => now()]);
        $untracked = SitePage::factory()->create(['tracked_at' => null]);
        $read = MeasurementRead::query()->create(['source' => 'gsc_pages', 'status' => ReadStatus::Complete,
            'window_from' => '2026-07-21', 'window_to' => '2026-09-14', 'started_at' => now(), 'finished_at' => now()]);
        foreach ([[$page, '2026-09-01', 7], [$otherPage, '2026-09-01', 5], [$page, '2026-09-02', 0], [$page, '2026-08-01', 90], [$untracked, '2026-09-01', 200]] as [$sitePage, $date, $clicks]) {
            PageMetric::query()->create(['site_page_id' => $sitePage->id, 'measurement_read_id' => $read->id,
                'measured_on' => $date, 'clicks' => $clicks, 'impressions' => $clicks * 10]);
        }
        $results = app(ManagerResults::class)->for($this->project)['search'];
        $daily = array_column($results['daily'], null, 'day');
        $this->assertSame(12, $results['clicks']);
        $this->assertSame(120, $results['impressions']);
        $this->assertCount(28, $daily);
        $this->assertSame(12, $daily['2026-09-01']['clicks']);
        $this->assertSame(2, $daily['2026-09-01']['observed_pages']);
        $this->assertSame(0, $daily['2026-09-02']['clicks']);
        $this->assertNull($daily['2026-09-03']['clicks']);
        $this->assertNull($results['previous_clicks'], 'A missing previous page is not a zero baseline.');
    }

    #[Test]
    public function purchases_use_only_the_primary_ledger_and_remain_unavailable_until_records_arrive(): void
    {
        $source = PurchaseSource::query()->create(['name' => 'Orders', 'kind' => 'manual', 'is_primary' => true, 'is_enabled' => true]);
        $this->assertNull(app(ManagerResults::class)->for($this->project)['purchases']['count']);
        $extra = PurchaseSource::query()->create(['name' => 'Duplicate import', 'kind' => 'manual', 'is_primary' => false, 'is_enabled' => true]);
        foreach ([$source, $extra] as $ledger) {
            app(PurchaseIntake::class)->receive($ledger, PurchasePayload::from([
                'schema_v' => 1, 'event_id' => (string) Str::uuid(), 'transaction_id' => 'order-1', 'revision' => 1,
                'occurred_at' => now()->toIso8601String(), 'purchased_at' => now()->subHour()->toIso8601String(),
                'collection_started_at' => '2026-09-01T00:00:00Z', 'status' => 'paid', 'amount_minor' => 12500,
                'refunded_minor' => 0, 'currency' => 'EUR', 'landing_url' => null,
                'attribution_status' => 'unattributed', 'is_new_customer' => null, 'items' => [['item_id' => 'cleaning', 'item_name' => 'Cleaning', 'quantity' => 1]], 'evidence' => 'Ledger test',
            ]), $this->owner->id);
        }
        $this->assertSame(1, app(ManagerResults::class)->for($this->project)['purchases']['count']);
        $other = Project::factory()->create();
        $this->assertNull(app(ManagerResults::class)->for($other)['purchases']['count']);
    }

    private function sampleRun(): AiSamplingRun
    {
        $set = AiSamplingSet::query()->first() ?? AiSamplingSet::query()->create(['version' => 1,
            'configuration' => [], 'configuration_hash' => str_repeat('a', 64), 'change_reason' => 'Dashboard test']);

        return AiSamplingRun::query()->create(['sampling_set_id' => $set->id, 'request_key' => (string) Str::uuid(),
            'purpose' => 'scheduled', 'status' => 'complete', 'finished_at' => now()]);
    }

    private function cell(AiSamplingRun $run, string $platform, ?bool $mention, string $purpose = 'discovery'): void
    {
        $cell = AiSamplingCell::query()->create(['sampling_run_id' => $run->id, 'cell_key' => hash('sha256', (string) Str::uuid()),
            'status' => $mention === null ? 'failed' : 'answered', 'specification' => ['prompt' => ['purpose' => $purpose], 'platform' => ['platform' => $platform]]]);
        if ($mention !== null) {
            AiSamplingAnswer::query()->create(['sampling_cell_id' => $cell->id, 'full_text' => 'Saved answer', 'sections' => [],
                'citations' => [], 'metadata' => [], 'content_hash' => str_repeat('a', 64), 'mentioned_in_text' => $mention,
                'cited_own_site' => false, 'received_at' => now()]);
        }
    }
}
