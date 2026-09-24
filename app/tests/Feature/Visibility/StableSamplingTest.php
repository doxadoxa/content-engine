<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Enums\BillingStatus;
use App\Enums\PipelineRunStatus;
use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Models\LlmPrompt;
use App\Models\LlmVisibilityAnswer;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\Contracts\LlmVisibilityGateway;
use App\Visibility\FakeLlmVisibility;
use App\Visibility\LlmAnswer;
use App\Visibility\Sampling\AnswerAllowance;
use App\Visibility\Sampling\SamplingReport;
use App\Visibility\Sampling\SamplingRuns;
use App\Visibility\Sampling\SamplingSets;
use App\Visibility\Sampling\SamplingTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StableSamplingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private FakeLlmVisibility $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['name' => 'Cleaning Point', 'website_url' => 'https://cleaningpoint.net', 'market' => 'pt']);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        app(CurrentProject::class)->set($this->project);
        /** @var FakeLlmVisibility $provider */
        $provider = app(LlmVisibilityGateway::class);
        $this->provider = $provider;
        // A billed question set must cover all four supported services.
        config()->set('visibility.platforms', ['chat_gpt' => ['model' => 'pinned-model', 'accepts_country' => true], 'gemini' => ['model' => 'gemini-model', 'accepts_country' => false],
            'claude' => ['model' => 'claude-model'], 'perplexity' => ['model' => 'perplexity-model']]);
        config()->set('queue.default', 'sync');
        Cache::flush();
    }

    #[Test]
    public function default_product_pins_the_instrument_until_an_owner_changes_it(): void
    {
        $this->assertTrue(config('visibility.stable_sampling'));
        LlmPrompt::factory()->for($this->project)->create(['text' => 'Who cleans homes in Lisbon?', 'locale' => 'en']);
        $first = app(SamplingSets::class)->ensure($this->project);
        $this->assertNotNull($first);
        $this->travel(100)->days();
        config()->set('visibility.platforms.chat_gpt.model', 'new-default');
        LlmPrompt::factory()->for($this->project)->create(['text' => 'Later generated question', 'locale' => 'en']);
        $same = app(SamplingSets::class)->ensure($this->project);
        $this->assertSame($first->id, $same?->id);
        $this->assertSame('pinned-model', $same->configuration['panel'][0]['model']);
        $next = $this->set([['text' => 'Which cleaners serve Lisbon?', 'locale' => 'en', 'intent' => 'buying', 'purpose' => 'discovery']], $first->id);
        $this->assertSame(2, $next->version);
        $this->assertSame('new-default', $next->configuration['panel'][0]['model']);
        $this->assertSame('Who cleans homes in Lisbon?', $first->refresh()->configuration['prompts'][0]['text']);
    }

    #[Test]
    public function brand_named_questions_cannot_inflate_discovery_visibility(): void
    {
        $this->expectException(ValidationException::class);
        $this->set([['text' => 'Where does Cleaning Point work?', 'locale' => 'en', 'intent' => 'buying', 'purpose' => 'discovery']]);
    }

    #[Test]
    public function full_answers_are_retained_with_separate_mentions_citations_and_accuracy_denominators(): void
    {
        $set = $this->set();
        $long = str_repeat('Consider local providers. ', 100).'Final detail with no brand name.';
        $this->provider->willReturn('chat_gpt', 'Who cleans homes in Lisbon?', new LlmAnswer('chat_gpt', 'resolved-v1', $long,
            [['url' => 'https://cleaningpoint.net/services', 'title' => 'Services']], .003, [['text' => $long, 'annotations' => []]],
            ['resolved_model' => 'resolved-v1', 'sent_country' => 'PT', 'web_search_reported' => true], .008));
        $this->provider->willAnswer('chat_gpt', 'What areas does Cleaning Point cover?', 'Cleaning Point covers Lisbon.');
        $run = $this->runSet($set);
        $report = app(SamplingReport::class)->run($run->refresh());
        $this->assertSame('complete', $report['status']);
        // One discovery question across four services; only ChatGPT's answer cites the site, and only it reports a total cost.
        $this->assertSame(4, $report['discovery']['answered']);
        $this->assertSame(0, $report['discovery']['mentions']);
        $this->assertSame(1, $report['discovery']['citations']);
        $this->assertSame(8000, $report['known_cost_micros']);
        $this->assertSame(7, $report['unknown_cost_cells']);
        $answer = AiSamplingAnswer::query()->where('full_text', $long)->firstOrFail();
        $this->actingAs($this->owner)->get(route('ai-sampling.answer', $answer))->assertOk()
            ->assertInertia(fn ($page) => $page->where('answer.full_text', $long)->where('answer.resolved_model', 'resolved-v1'));
        $this->assertSame(8000, (int) PipelineStep::query()->sum('cost_micros'));
    }

    #[Test]
    public function duplicate_submission_does_not_buy_twice_but_explicit_same_day_recheck_does(): void
    {
        $set = $this->set();
        $key = (string) Str::uuid();
        $run = $this->runSet($set, $key);
        $duplicate = $this->runSet($set, $key);
        $this->assertSame($run->id, $duplicate->id);
        $this->assertCount(8, $this->provider->asked());
        $cell = AiSamplingCell::query()->where('sampling_run_id', $run->id)->firstOrFail();
        $again = app(SamplingRuns::class)->start($this->project, $set, (string) Str::uuid(), $this->owner, $run, [$cell->cell_key]);
        app(SamplingRuns::class)->dispatch($this->project, $again);
        $this->assertCount(9, $this->provider->asked());
        $this->assertSame(1, AiSamplingCell::query()->where('sampling_run_id', $again->id)->count());
        $this->assertSame($run->id, $again->recheck_of_run_id);
    }

    #[Test]
    public function model_retirement_prevents_purchase_and_preserves_the_planned_cells(): void
    {
        $set = $this->set();
        foreach (['chat_gpt', 'gemini', 'claude', 'perplexity'] as $platform) {
            $this->provider->withModels($platform, [['model_name' => 'other-model', 'web_search_supported' => true]]);
        }
        $run = $this->runSet($set);
        $this->assertCount(0, $this->provider->asked());
        $this->assertSame(8, AiSamplingCell::query()->where('status', 'unavailable')->count());
        $this->assertSame('partial', $run->refresh()->status);
        $this->assertNull(app(SamplingReport::class)->run($run)['discovery']['mention_rate']);
    }

    #[Test]
    public function ambiguous_outcomes_are_not_automatically_rebought(): void
    {
        $this->provider->failEverything();
        $run = $this->runSet($this->set());
        $cell = AiSamplingCell::query()->where('sampling_run_id', $run->id)->firstOrFail();
        $this->assertSame('indeterminate', $cell->status);
        app(PipelineRunner::class)->start('ai_sample', $this->project, ['cell_id' => $cell->id]);
        $this->assertCount(8, $this->provider->asked());
        $this->assertSame(0, AiSamplingAnswer::query()->count());
        $this->assertSame(8, app(SamplingReport::class)->run($run->refresh())['unknown_cost_cells']);
    }

    #[Test]
    public function an_empty_answer_keeps_its_cost_and_the_budget_keeps_missing_cells_visible(): void
    {
        config()->set('visibility.max_answers_per_run', 1);
        $this->provider->willReturn('chat_gpt', 'Who cleans homes in Lisbon?', new LlmAnswer('chat_gpt', 'resolved', '', [], .001, [], ['resolved_model' => 'resolved'], .006));
        $run = $this->runSet($this->set());
        $report = app(SamplingReport::class)->run($run->refresh());
        $this->assertSame('partial', $report['status']);
        $this->assertSame(['empty' => 1, 'budget_skipped' => 7], $report['status_counts']);
        $this->assertNull($report['discovery']['mention_rate']);
        $this->assertSame(6000, $report['known_cost_micros']);
    }

    #[Test]
    public function comparison_requires_observed_models_and_matching_actual_context(): void
    {
        $set = $this->set([['text' => 'Who cleans homes in Lisbon?', 'locale' => 'en', 'intent' => 'buying', 'purpose' => 'discovery']]);
        foreach (['v1', 'v1', 'v2'] as $model) {
            foreach (['chat_gpt', 'gemini', 'claude', 'perplexity'] as $platform) {
                $this->provider->willReturn($platform, 'Who cleans homes in Lisbon?', new LlmAnswer($platform, $model, 'An answer.', [], .001, [],
                    ['resolved_model' => $model, 'sent_country' => 'PT', 'web_search_reported' => true], .006));
            }
            $run = $this->runSet($set);
            $available[] = app(SamplingReport::class)->run($run->refresh())['comparison']['available'];
        }
        $this->assertSame([false, true, false], $available);
    }

    #[Test]
    public function reads_never_call_providers_and_mutations_require_the_current_project_owner(): void
    {
        $set = $this->set();
        $viewer = User::factory()->create();
        $viewer->projects()->attach($this->project, ['role' => 'editor']);
        $this->actingAs($viewer)->get('/visibility')->assertOk();
        $this->actingAs($viewer)->post(route('ai-sampling.sample'), ['request_key' => (string) Str::uuid(), 'set_id' => $set->id])->assertForbidden();
        $this->assertCount(0, $this->provider->asked());
        $other = Project::factory()->create();
        $otherSet = app(CurrentProject::class)->run($other, fn () => AiSamplingSet::query()->create(['version' => 1, 'configuration' => [], 'configuration_hash' => str_repeat('f', 64), 'change_reason' => 'Other tenant']));
        $this->actingAs($this->owner)->post(route('ai-sampling.sample'), ['request_key' => (string) Str::uuid(), 'set_id' => $otherSet->id])->assertNotFound();
    }

    #[Test]
    public function the_active_visibility_pipeline_creates_a_fixed_run_without_legacy_excerpt_writes(): void
    {
        config()->set('visibility.prompts_per_locale', 1);
        LlmPrompt::factory()->for($this->project)->create(['text' => 'Who cleans homes in Lisbon?', 'locale' => 'en']);
        $pipeline = app(PipelineRunner::class)->start('visibility', $this->project);
        $this->assertSame(PipelineRunStatus::Completed, $pipeline->refresh()->status);
        $run = AiSamplingRun::query()->sole();
        app(SamplingRuns::class)->dispatch($this->project, $run);
        $this->assertSame(1, AiSamplingSet::query()->count());
        $this->assertSame(4, AiSamplingAnswer::query()->count());
        $this->assertSame(0, LlmVisibilityAnswer::query()->count());
        $this->assertCount(4, $this->provider->asked());
    }

    #[Test]
    public function reconciliation_recovers_only_unattempted_work_and_labels_stale_attempts(): void
    {
        $set = $this->set();
        $run = AiSamplingRun::query()->create(['sampling_set_id' => $set->id, 'request_key' => 'recovery-test', 'purpose' => 'test', 'status' => 'running']);
        $spec = ['prompt' => $set->configuration['prompts'][0], 'platform' => $set->configuration['panel'][0], 'market' => 'pt', 'brand' => 'Cleaning Point', 'website' => 'https://cleaningpoint.net'];
        $stale = AiSamplingCell::query()->create(['sampling_run_id' => $run->id, 'cell_key' => hash('sha256', 'stale'), 'specification' => $spec, 'status' => 'running', 'attempted_at' => SamplingTiming::staleBefore()->subSecond()]);
        $queued = AiSamplingCell::query()->create(['sampling_run_id' => $run->id, 'cell_key' => hash('sha256', 'queued'), 'specification' => $spec, 'status' => 'queued']);
        // Queued work only runs on a reserved answer unit, exactly as SamplingRuns would have left it.
        app(AnswerAllowance::class)->reserve($this->project, $queued);
        /** @var PendingCommand $command */
        $command = $this->artisan('visibility:reconcile');
        $command->assertSuccessful()->run();
        $this->assertSame('indeterminate', $stale->refresh()->status);
        $this->assertSame('answered', $queued->refresh()->status);
        $this->assertCount(1, $this->provider->asked());
        $this->assertSame('partial', $run->refresh()->status);
    }

    #[Test]
    public function a_losing_preflight_cannot_hide_an_answer_recorded_by_another_worker(): void
    {
        $set = $this->set([['text' => 'Who cleans homes in Lisbon?', 'locale' => 'en', 'intent' => 'buying', 'purpose' => 'discovery']]);
        $entered = false;
        $this->provider->whenReadingModels(function (string $platform) use (&$entered): void {
            if ($entered) {
                return;
            }
            $entered = true;
            $cell = AiSamplingCell::query()->get()->firstOrFail(fn (AiSamplingCell $cell): bool => $cell->specification['platform']['platform'] === $platform);
            app(PipelineRunner::class)->start('ai_sample', $this->project, ['cell_id' => $cell->id]);
            throw new \RuntimeException('The original worker inventory request failed after the competing worker completed.');
        });
        $run = $this->runSet($set);
        // Every cell, including the one whose original worker lost its preflight, is answered exactly once.
        $this->assertSame(4, AiSamplingCell::query()->where('status', 'answered')->count());
        $this->assertSame(4, AiSamplingAnswer::query()->count());
        $this->assertSame('complete', $run->refresh()->status);
        $this->assertCount(4, $this->provider->asked());
        $this->assertSame(4, app(SamplingReport::class)->run($run)['discovery']['answered']);
    }

    #[Test]
    public function canceled_billing_refuses_direct_sampling_and_old_queued_cells(): void
    {
        $set = $this->set();
        config()->set('queue.default', 'database');
        $run = app(SamplingRuns::class)->start($this->project, $set, (string) Str::uuid(), $this->owner);
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['status' => BillingStatus::Canceled]);
        $this->actingAs($this->owner)->postJson(route('ai-sampling.sample'), ['set_id' => $set->id, 'request_key' => (string) Str::uuid()])->assertConflict();
        config()->set('queue.default', 'sync');
        foreach (AiSamplingCell::query()->where('sampling_run_id', $run->id)->get() as $cell) {
            app(PipelineRunner::class)->start('ai_sample', $this->project, ['cell_id' => $cell->id]);
        }
        $this->assertCount(0, $this->provider->asked());
        $this->assertSame(0, AiSamplingCell::query()->whereNotNull('attempted_at')->count());
        $this->assertSame('partial', $run->refresh()->status);
    }

    #[Test]
    public function valid_running_requests_survive_recovery_reporting_duplicate_workers_and_manual_recheck_until_the_deadline_plus_grace(): void
    {
        $this->freezeTime();
        [$run, $cell] = $this->runningCell();
        $cell->update(['attempted_at' => now()->subSeconds(SamplingTiming::stepSeconds() - 1)]);
        $this->assertSame('running', app(SamplingReport::class)->run($run)['cells'][0]['status']);
        $this->actingAs($this->owner)->postJson(route('ai-sampling.recheck-cell', $cell), ['request_key' => (string) Str::uuid()])->assertConflict();
        /** @var PendingCommand $first */
        $first = $this->artisan('visibility:reconcile');
        $first->assertSuccessful()->run();
        app(PipelineRunner::class)->start('ai_sample', $this->project, ['cell_id' => $cell->id]);
        $this->assertSame('running', $cell->refresh()->status);
        $this->assertCount(0, $this->provider->asked());

        // Exactly at deadline + grace is still not abandoned; the predicate is strictly older.
        $cell->update(['attempted_at' => SamplingTiming::staleBefore()]);
        /** @var PendingCommand $boundary */
        $boundary = $this->artisan('visibility:reconcile');
        $boundary->assertSuccessful()->run();
        $this->assertSame('running', $cell->refresh()->status);
        $this->assertSame('running', app(SamplingReport::class)->run($run)['cells'][0]['status']);
        $this->actingAs($this->owner)->postJson(route('ai-sampling.recheck-cell', $cell), ['request_key' => (string) Str::uuid()])->assertConflict();

        $this->travel(1)->seconds();
        /** @var PendingCommand $expired */
        $expired = $this->artisan('visibility:reconcile');
        $expired->assertSuccessful()->run();
        $this->assertSame('indeterminate', $cell->refresh()->status);
        $this->assertSame('indeterminate', app(SamplingReport::class)->run($run)['cells'][0]['status']);
        $this->assertCount(0, $this->provider->asked());
    }

    #[Test]
    public function a_late_answer_receipt_between_the_stale_read_and_update_is_preserved(): void
    {
        [$run, $cell] = $this->runningCell();
        $cell->update(['attempted_at' => SamplingTiming::staleBefore()->subSecond()]);
        $received = false;
        AiSamplingCell::retrieved(function (AiSamplingCell $read) use ($cell, &$received): void {
            if ($read->id !== $cell->id || $received) {
                return;
            }
            $received = true;
            AiSamplingAnswer::query()->create(['sampling_cell_id' => $cell->id, 'full_text' => 'A late recorded provider response.',
                'sections' => [['text' => 'A late recorded provider response.', 'annotations' => []]], 'citations' => [], 'metadata' => [],
                'content_hash' => hash('sha256', 'A late recorded provider response.'), 'received_at' => now(), 'total_cost_micros' => 1234]);
            AiSamplingCell::query()->whereKey($cell->id)->update(['status' => 'answered', 'reason' => null, 'finished_at' => now()]);
        });
        app(PipelineRunner::class)->start('ai_sample', $this->project, ['cell_id' => $cell->id]);
        $this->assertTrue($received);
        $this->assertSame('answered', $cell->refresh()->status);
        $this->assertNull($cell->reason);
        $this->assertSame('complete', $run->refresh()->status);
        $this->assertSame(1234, AiSamplingAnswer::query()->sole()->total_cost_micros);
        $this->assertCount(0, $this->provider->asked());
    }

    /** @return array{AiSamplingRun,AiSamplingCell} */
    private function runningCell(): array
    {
        $set = $this->set([['text' => 'Who cleans homes in Lisbon?', 'locale' => 'en', 'intent' => 'buying', 'purpose' => 'discovery']]);
        $run = AiSamplingRun::query()->create(['sampling_set_id' => $set->id, 'request_key' => (string) Str::uuid(), 'purpose' => 'test', 'status' => 'running', 'requested_by' => $this->owner->id]);
        $spec = ['prompt' => $set->configuration['prompts'][0], 'platform' => $set->configuration['panel'][0], 'market' => 'pt', 'brand' => 'Cleaning Point', 'website' => 'https://cleaningpoint.net'];
        $cell = AiSamplingCell::query()->create(['sampling_run_id' => $run->id, 'cell_key' => hash('sha256', 'running'), 'specification' => $spec, 'status' => 'running', 'attempted_at' => now()]);

        return [$run, $cell];
    }

    /** @param list<array<string, string>>|null $prompts */
    private function set(?array $prompts = null, ?string $previous = null): AiSamplingSet
    {
        return app(SamplingSets::class)->save($this->project, $this->owner, ['expected_set_id' => $previous, 'reason' => 'Reviewed test instrument.', 'prompts' => $prompts ?? [
            ['text' => 'Who cleans homes in Lisbon?', 'locale' => 'en', 'intent' => 'buying', 'purpose' => 'discovery'],
            ['text' => 'What areas does Cleaning Point cover?', 'locale' => 'en', 'intent' => 'learning', 'purpose' => 'accuracy'],
        ]]);
    }

    private function runSet(AiSamplingSet $set, ?string $key = null): AiSamplingRun
    {
        $run = app(SamplingRuns::class)->start($this->project, $set, $key ?? (string) Str::uuid(), $this->owner);
        app(SamplingRuns::class)->dispatch($this->project, $run);

        return $run;
    }
}
