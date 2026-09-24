<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Enums\BillingStatus;
use App\Enums\ProjectStatus;
use App\Models\AiAnswerReservation;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingCycle;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Models\LlmPrompt;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Steps\AiSampling\SampleAnswer;
use App\Pipelines\Steps\Visibility\AskAssistants;
use App\Pipelines\Steps\Visibility\GeneratePrompts;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\Contracts\LlmVisibilityGateway;
use App\Visibility\FakeLlmVisibility;
use App\Visibility\Sampling\SamplingRuns;
use App\Visibility\Sampling\SamplingSchedule;
use App\Visibility\Sampling\SamplingSets;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PackagedSamplingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private FakeLlmVisibility $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-31T12:00:00Z'));
        Queue::fake();
        Cache::flush();
        $this->project = Project::factory()->create(['name' => 'Example Cleaning', 'default_locale' => 'en', 'locales' => ['en']]);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        app(CurrentProject::class)->set($this->project);
        $provider = app(LlmVisibilityGateway::class);
        $this->assertInstanceOf(FakeLlmVisibility::class, $provider);
        $this->provider = $provider;
        $this->plan('starter');
    }

    /** @return array<string,array{int}> */
    public static function periodLengths(): array
    {
        return ['28 days' => [28], '29 days' => [29], '30 days' => [30], '31 days' => [31]];
    }

    #[Test]
    #[DataProvider('periodLengths')]
    public function monthly_checks_follow_the_actual_billing_period(int $days): void
    {
        $this->plan('starter', $days);
        $this->set(3);
        $first = app(SamplingSchedule::class)->dispatchDue($this->project);
        $this->assertNotNull($first);
        $this->assertSame(12, AiAnswerReservation::query()->count());
        $this->assertSame(12, $this->used());
        $this->travel($days)->days();
        // An expired local period cannot spend until billing records its renewal.
        $this->assertNull(app(SamplingSchedule::class)->dispatchDue($this->project));
        $this->assertSame(1, AiSamplingRun::query()->count());
        $this->plan('starter', $days);
        $next = app(SamplingSchedule::class)->dispatchDue($this->project);
        $this->assertNotNull($next);
        $this->assertNotSame($first->id, $next->id);
        $this->assertSame(2, AiSamplingCycle::query()->count());
        $this->assertSame(12, $this->used());
    }

    #[Test]
    public function growth_supports_five_weekly_batches_and_never_a_sixth_paid_batch(): void
    {
        $this->plan('growth', 31);
        $this->set(10);
        for ($week = 0; $week < 5; $week++) {
            if ($week > 0) {
                $this->travel(7)->days();
            }
            $this->assertNotNull(app(SamplingSchedule::class)->dispatchDue($this->project));
            app(SamplingSchedule::class)->dispatchDue($this->project);
            $this->assertSame(($week + 1) * 40, $this->used());
        }
        $this->assertSame(5, AiSamplingCycle::query()->count());
        $this->assertSame(200, AiAnswerReservation::query()->count());
        $this->expectException(HttpException::class);
        $this->collectRun(AiSamplingSet::query()->sole());
    }

    #[Test]
    public function missed_weeks_are_not_backfilled(): void
    {
        $this->plan('growth', 31);
        $this->set(10);
        $this->travel(23)->days();
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $this->assertSame(3, AiSamplingCycle::query()->sole()->slot);
        $this->assertSame(40, $this->used());
    }

    #[Test]
    public function a_growth_trial_has_starter_observation_limits(): void
    {
        $this->plan('growth', 3, BillingStatus::Trialing);
        $set = $this->set(3);
        $run = $this->collectRun($set);
        $this->assertSame(12, $this->used());
        $this->assertSame(3, app(SamplingSchedule::class)->describe($this->project)['questions']);
        $cell = AiSamplingCell::query()->where('sampling_run_id', $run->id)->firstOrFail();
        $this->expectException(HttpException::class);
        app(SamplingRuns::class)->start($this->project, $set, (string) Str::uuid(), $this->owner, $run, [$cell->cell_key]);
    }

    #[Test]
    public function a_repeated_submission_is_idempotent_and_manual_rechecks_share_quota(): void
    {
        $set = $this->set(1);
        $key = (string) Str::uuid();
        $run = app(SamplingRuns::class)->start($this->project, $set, $key, $this->owner);
        $this->assertSame($run->id, app(SamplingRuns::class)->start($this->project, $set, $key, $this->owner)->id);
        $cell = AiSamplingCell::query()->where('sampling_run_id', $run->id)->firstOrFail();
        app(SamplingRuns::class)->start($this->project, $set, (string) Str::uuid(), $this->owner, $run, [$cell->cell_key]);
        $this->assertSame(5, $this->used());
        $this->assertSame(5, AiAnswerReservation::query()->count());
    }

    #[Test]
    public function paid_failures_keep_their_units_and_duplicate_workers_do_not_buy_retries(): void
    {
        $this->provider->failEverything();
        $run = $this->collectRun($this->set(3));
        $this->execute($run);
        $this->execute($run);
        $this->assertCount(12, $this->provider->asked());
        $this->assertSame(12, $this->used());
        $this->assertSame(12, AiAnswerReservation::query()->where('status', 'attempted')->count());
        $this->assertSame(12, AiSamplingCell::query()->where('status', 'indeterminate')->count());
    }

    #[Test]
    public function unavailable_inventory_releases_only_unattempted_reservations(): void
    {
        $this->provider->unconfigured();
        $run = $this->collectRun($this->set(3));
        $this->execute($run);
        $this->execute($run);
        $this->assertCount(0, $this->provider->asked());
        $this->assertSame(0, $this->used());
        $this->assertSame(12, AiAnswerReservation::query()->where('status', 'released')->count());
    }

    #[Test]
    public function queued_old_period_work_cannot_consume_a_renewed_allowance(): void
    {
        $run = $this->collectRun($this->set(3));
        $this->travel(31)->days();
        $this->plan('starter');
        $this->execute($run);
        $this->assertCount(0, $this->provider->asked());
        $this->assertSame(0, $this->used());
        $this->assertSame(12, AiAnswerReservation::query()->where('status', 'released')->count());
        $this->collectRun(AiSamplingSet::query()->sole());
        $this->assertSame(12, $this->used());
    }

    #[Test]
    public function cancellation_after_queueing_prevents_paid_work_and_releases_units(): void
    {
        $run = $this->collectRun($this->set(1));
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['status' => BillingStatus::Canceled]);
        $this->execute($run);
        $this->assertCount(0, $this->provider->asked());
        $this->assertSame(0, $this->used());
    }

    #[Test]
    public function an_upgrade_adds_only_the_incremental_allowance_in_the_same_period(): void
    {
        $this->collectRun($this->set(3));
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['plan' => 'growth']);
        app(Entitlements::class)->forget($this->project);
        $this->assertSame(188, app(Entitlements::class)->for($this->project)->remaining(Metric::AiAnswers));
        $set = $this->set(10);
        $this->collectRun($set);
        $this->assertSame(52, $this->used());
    }

    #[Test]
    public function saving_too_many_questions_refuses_without_replacing_the_existing_set(): void
    {
        $this->set(3);
        try {
            $this->set(4);
            $this->fail('Excess questions must be refused.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('prompts', $error->errors());
        }
        $this->assertSame(1, AiSamplingSet::query()->count());
    }

    #[Test]
    public function legacy_visibility_cannot_bypass_the_quota_even_with_the_feature_flag_disabled(): void
    {
        config(['visibility.stable_sampling' => false]);
        $this->set(3);
        $runner = app(PipelineRunner::class);
        for ($i = 0; $i < 2; $i++) {
            $pipeline = $runner->start('visibility', $this->project);
            $runner->execute($pipeline, GeneratePrompts::key());
            $runner->execute($pipeline, AskAssistants::key());
        }
        $this->assertSame(1, AiSamplingRun::query()->count());
        $this->assertSame(12, $this->used());
        $this->assertCount(0, $this->provider->asked());
    }

    #[Test]
    public function initial_question_work_has_one_durable_slot_and_ignores_repeated_dispatch(): void
    {
        app(SamplingSchedule::class)->dispatchDue($this->project);
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'visibility')->count());
        $this->assertSame(1, AiSamplingCycle::query()->count());
        // Existing generated prompts are limited to the package and default language.
        for ($i = 0; $i < 6; $i++) {
            LlmPrompt::factory()->for($this->project)->create(['locale' => 'en', 'text' => 'Which provider handles saved task '.$i.'?']);
        }
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $this->assertCount(3, AiSamplingSet::query()->sole()->configuration['prompts']);
        $this->assertSame(12, $this->used());
    }

    #[Test]
    public function a_paused_project_cannot_start_new_manual_checks(): void
    {
        $set = $this->set(1);
        $this->project->update(['status' => ProjectStatus::Paused]);
        $this->expectException(HttpException::class);
        $this->collectRun($set);
    }

    #[Test]
    public function initialization_writes_the_packaged_question_count_once_and_starts_its_batch(): void
    {
        $models = app(ModelGateway::class);
        $this->assertInstanceOf(FakeModelGateway::class, $models);
        $models->willAnswerRole('utility', "PROMPT: Who cleans homes locally? | buying\nPROMPT: Which cleaners provide supplies? | comparison\nPROMPT: How can I compare cleaning quotes? | learning");
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $cycle = AiSamplingCycle::query()->sole();
        $pipeline = PipelineRun::query()->findOrFail($cycle->pipeline_run_id);
        $runner = app(PipelineRunner::class);
        $runner->execute($pipeline, GeneratePrompts::key());
        $runner->execute($pipeline, AskAssistants::key());
        $runner->execute($pipeline, GeneratePrompts::key());
        $this->assertCount(1, $models->sent());
        $this->assertSame(3, LlmPrompt::query()->count());
        $this->assertSame(12, $this->used());
        $this->assertNotNull($cycle->fresh()->sampling_run_id);
    }

    #[Test]
    public function delayed_question_initialization_does_not_backfill_an_old_week(): void
    {
        $this->plan('growth', 31);
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $cycle = AiSamplingCycle::query()->sole();
        $this->travel(15)->days();
        $this->set(10);
        $this->assertNull(app(SamplingSchedule::class)->finishInitialization($this->project, $cycle->id));
        $this->assertSame(0, $this->used());
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $this->assertSame(40, $this->used());
    }

    #[Test]
    public function a_queued_cell_without_a_reservation_has_no_free_attempt(): void
    {
        $run = $this->collectRun($this->set(1));
        // Only a reserved unit can pay for a request; a cell that lost its reservation is not a free path.
        AiAnswerReservation::query()->delete();
        $this->execute($run);
        $this->assertCount(0, $this->provider->asked());
        $this->assertSame(4, $this->used());
        $this->assertSame(4, AiSamplingCell::query()->where('status', 'unavailable')->count());
    }

    #[Test]
    public function a_downgrade_preserves_history_and_creates_a_deterministic_three_question_set(): void
    {
        $this->plan('growth');
        $old = $this->set(10);
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $this->travel(31)->days();
        $this->plan('starter');
        $run = app(SamplingSchedule::class)->dispatchDue($this->project);
        $this->assertNotNull($run);
        $new = AiSamplingSet::query()->latest('version')->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertCount(10, $old->fresh()->configuration['prompts']);
        $this->assertEquals(array_slice($old->configuration['prompts'], 0, 3), $new->configuration['prompts']);
        $this->assertSame(12, $this->used());
    }

    #[Test]
    public function an_upgrade_fills_new_questions_once_without_changing_previous_evidence(): void
    {
        $old = $this->set(3);
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $this->travel(7)->days();
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['plan' => 'growth']);
        app(Entitlements::class)->forget($this->project);
        $models = app(ModelGateway::class);
        $this->assertInstanceOf(FakeModelGateway::class, $models);
        $models->willAnswerRole('utility', implode("\n", array_map(fn (int $i): string => "PROMPT: Who provides cleaning option {$i}? | buying", range(4, 10))));
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $cycle = AiSamplingCycle::query()->where('slot', 1)->firstOrFail();
        $pipeline = PipelineRun::query()->findOrFail($cycle->pipeline_run_id);
        app(PipelineRunner::class)->execute($pipeline, GeneratePrompts::key());
        app(PipelineRunner::class)->execute($pipeline, AskAssistants::key());
        app(SamplingSchedule::class)->dispatchDue($this->project);
        $new = AiSamplingSet::query()->latest('version')->firstOrFail();
        $this->assertCount(3, $old->fresh()->configuration['prompts']);
        $this->assertCount(10, $new->configuration['prompts']);
        $this->assertEquals($old->configuration['prompts'], array_slice($new->configuration['prompts'], 0, 3));
        $this->assertCount(1, $models->sent());
        $this->assertSame(52, $this->used());
    }

    #[Test]
    public function worker_billing_and_pause_guards_are_fresh_after_inventory_returns(): void
    {
        $run = $this->collectRun($this->set(1));
        $this->provider->whenReadingModels(function (): void {
            $this->project->update(['status' => ProjectStatus::Paused]);
        });
        $this->execute($run);
        $this->assertCount(0, $this->provider->asked());
        $this->assertSame(0, $this->used());
    }

    #[Test]
    public function saving_questions_for_another_project_is_not_a_quota_escape(): void
    {
        $other = Project::factory()->create();
        $set = app(CurrentProject::class)->run($other, fn () => AiSamplingSet::query()->create(['version' => 1, 'configuration' => [], 'configuration_hash' => str_repeat('a', 64), 'change_reason' => 'Different tenant.']));
        $this->expectException(HttpException::class);
        $this->collectRun($set);
    }

    #[Test]
    public function the_screen_discloses_period_usage_and_question_limits(): void
    {
        $this->collectRun($this->set(3));
        $this->actingAs($this->owner)->get(route('visibility.index'))->assertOk()->assertInertia(fn ($page) => $page->where('sampling.allowance.used', 12)->where('sampling.allowance.reserved', 12)->where('sampling.allowance.remaining', 0)->where('sampling.allowance.questions', 3)->where('sampling.allowance.frequency', 'monthly'));
    }

    private function plan(string $key, int $days = 31, BillingStatus $status = BillingStatus::Active): void
    {
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['plan' => $key, 'status' => $status,
            'period_started_at' => now(), 'period_ends_at' => now()->addDays($days), 'trial_ends_at' => $status === BillingStatus::Trialing ? now()->addDays($days) : null]);
        app(Entitlements::class)->forget($this->project);
    }

    private function set(int $count): AiSamplingSet
    {
        return app(SamplingSets::class)->save($this->project, $this->owner, ['expected_set_id' => AiSamplingSet::query()->latest('version')->first()?->id, 'reason' => 'Reviewed package questions.',
            'prompts' => array_map(fn (int $i): array => ['text' => "Which local cleaning provider handles task {$i}?", 'locale' => 'en', 'intent' => 'buying', 'purpose' => 'discovery'], range(1, $count))]);
    }

    private function collectRun(AiSamplingSet $set): AiSamplingRun
    {
        return app(SamplingRuns::class)->start($this->project, $set, (string) Str::uuid(), $this->owner);
    }

    private function execute(AiSamplingRun $run): void
    {
        foreach (AiSamplingCell::query()->where('sampling_run_id', $run->id)->get() as $cell) {
            app(PipelineRunner::class)->execute(PipelineRun::query()->findOrFail($cell->pipeline_run_id), SampleAnswer::key());
        }
    }

    private function used(): int
    {
        app(Entitlements::class)->forget($this->project);

        return app(Entitlements::class)->for($this->project)->used(Metric::AiAnswers);
    }
}
