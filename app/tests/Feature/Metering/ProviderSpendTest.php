<?php

declare(strict_types=1);

namespace Tests\Feature\Metering;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelCatalog;
use App\Ai\ModelRequest;
use App\Enums\BillingStatus;
use App\Facts\DurableFactSession;
use App\Facts\FactSpendingRefused;
use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\ProviderSpendRecord;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Steps\AiAccuracy\CheckAccuracy;
use App\Pipelines\Steps\AiSampling\SampleAnswer;
use App\Support\Metering\ProjectSpend;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ProviderSpendTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function an_answer_saved_before_step_metering_is_counted_once_and_in_all_project_totals(): void
    {
        [$run, $step] = $this->answer(42000);
        $before = ProjectSpend::for($this->project, now()->subDay())->toArray();
        $this->assertSame(42000, $before['total_micros']);
        $this->assertSame(42000, $before['recovered_provider_micros']);
        $this->assertSame('complete', $before['completeness']);
        $this->assertSame(42000, ProjectSpend::totals([$this->project->id], now()->subDay())[$this->project->id]);
        $step->update(['cost_micros' => 42000]);
        $normal = ProjectSpend::for($this->project, now()->subDay())->toArray();
        $this->assertSame(42000, $normal['total_micros']);
        $this->assertSame(0, $normal['recovered_provider_micros']);
        $this->assertSame(0, $run->cost_micros);
    }

    #[Test]
    public function an_unknown_answer_cost_is_not_a_free_success(): void
    {
        $this->answer(null);
        $report = ProjectSpend::for($this->project, now()->subDay())->toArray();
        $this->assertSame(1, $report['unknown_provider_attempts']);
        $this->assertSame('incomplete', $report['completeness']);
        $this->assertSame(0, $report['total_micros']);
    }

    #[Test]
    public function checker_call_receipts_survive_before_any_assessment_result_or_step_rollup(): void
    {
        [$context, $step] = $this->context();
        $session = new DurableFactSession($context, app(ModelCatalog::class));
        $response = $session->send(ModelRequest::for('factcheck', 'Synthetic evidence for metering.'));
        $record = ProviderSpendRecord::query()->sole();
        $expected = app(ModelCatalog::class)->cost($response->model, $response->inputTokens, $response->outputTokens, $context->run->price_list_version);
        $this->assertSame($expected, $record->cost_micros);
        $this->assertSame($expected, ProjectSpend::total($this->project, now()->subDay()));
        $this->assertSame('reported', $record->status);
        $step->update(['cost_micros' => $expected]);
        $this->assertSame($expected, ProjectSpend::total($this->project, now()->subDay()));
        $this->assertSame(0, ProjectSpend::for($this->project, now()->subDay())->toArray()['recovered_provider_micros']);
    }

    #[Test]
    public function pending_and_unpriced_attempts_remain_explicit_even_when_another_call_is_metered(): void
    {
        [$context] = $this->context();
        ProviderSpendRecord::query()->create(['pipeline_run_id' => $context->run->id, 'step_key' => CheckAccuracy::key(), 'status' => 'pending', 'provider' => 'test', 'model' => 'unknown', 'role' => 'factcheck', 'price_list_version' => 1, 'request_hash' => str_repeat('a', 64)]);
        config()->set('models.prices.versions.1', []);
        (new DurableFactSession($context, app(ModelCatalog::class)))->send(ModelRequest::for('factcheck', 'Synthetic unpriced response.'));
        $report = ProjectSpend::for($this->project, now()->subDay())->toArray();
        $this->assertSame(2, $report['unknown_provider_attempts']);
        $this->assertSame('incomplete', $report['completeness']);
        $this->assertNull(ProviderSpendRecord::query()->where('status', 'unpriced')->sole()->cost_micros);
    }

    #[Test]
    public function a_failed_provider_call_leaves_a_durable_unknown_outcome_without_a_fabricated_price(): void
    {
        [$context] = $this->context();
        /** @var FakeModelGateway $gateway */
        $gateway = app(ModelGateway::class);
        $gateway->willThrow(fn () => throw new RuntimeException('Transport failed.'));
        try {
            (new DurableFactSession($context, app(ModelCatalog::class)))->send(ModelRequest::for('factcheck', 'Synthetic failure.'));
            $this->fail('The transport failure must be returned.');
        } catch (RuntimeException $error) {
            $this->assertSame('Transport failed.', $error->getMessage());
        }
        $this->assertSame('unknown', ProviderSpendRecord::query()->sole()->status);
        $this->assertSame(1, ProjectSpend::for($this->project, now()->subDay())->toArray()['unknown_provider_attempts']);
    }

    #[Test]
    public function checker_entitlement_is_rechecked_before_every_paid_call_without_recording_a_refusal_as_spend(): void
    {
        [$context] = $this->context();
        $session = new DurableFactSession($context, app(ModelCatalog::class));
        $session->send(ModelRequest::for('factcheck', 'First supported call.'));
        ProjectSubscription::query()->where('project_id', $this->project->id)->update(['status' => BillingStatus::Canceled]);
        try {
            $session->send(ModelRequest::for('factcheck', 'Forbidden second call.'));
            $this->fail('Canceled project must be refused.');
        } catch (FactSpendingRefused) {
            $this->assertSame(1, ProviderSpendRecord::query()->count());
        }
    }

    #[Test]
    public function a_receipt_crossing_midnight_stays_with_its_step_and_does_not_leak_into_another_tenant(): void
    {
        [, $step] = $this->answer(9000);
        $start = CarbonImmutable::parse('2026-09-14T00:00:00Z');
        $end = $start->addDay();
        $step->forceFill(['created_at' => $end->subSecond()])->save();
        $this->assertSame(9000, ProjectSpend::total($this->project, $start, $end));
        $this->assertSame(0, ProjectSpend::total($this->project, $end, $end->addDay()));
        $other = Project::factory()->create();
        $this->assertSame(0, ProjectSpend::total($other, $start));
    }

    #[Test]
    public function a_legacy_duplicate_dispatch_pointer_cannot_double_count_an_already_metered_answer(): void
    {
        [$original, $step] = $this->answer(100);
        $step->update(['cost_micros' => 100]);
        $duplicate = PipelineRun::factory()->for($this->project)->create(['pipeline' => 'ai_sample', 'input' => $original->input]);
        PipelineStep::factory()->for($duplicate, 'pipelineRun')->create(['step_key' => SampleAnswer::key(), 'cost_micros' => 0]);
        AiSamplingCell::query()->update(['pipeline_run_id' => $duplicate->id]);
        $report = ProjectSpend::for($this->project, now()->subDay())->toArray();
        $this->assertSame(100, $report['total_micros']);
        $this->assertSame(0, $report['recovered_provider_micros']);
        $this->assertSame(1, $report['ambiguous_provider_records']);
        $this->assertSame('incomplete', $report['completeness']);
    }

    /** @return array{PipelineRun,PipelineStep} */
    private function answer(?int $cost): array
    {
        $set = AiSamplingSet::query()->create(['version' => 1, 'configuration' => [], 'configuration_hash' => str_repeat('b', 64), 'change_reason' => 'Synthetic cost fixture']);
        $sample = AiSamplingRun::query()->create(['sampling_set_id' => $set->id, 'request_key' => (string) Str::uuid(), 'purpose' => 'test', 'status' => 'complete']);
        $cell = AiSamplingCell::query()->create(['sampling_run_id' => $sample->id, 'cell_key' => str_repeat('c', 64), 'specification' => [], 'status' => 'answered', 'attempted_at' => now()]);
        $run = PipelineRun::factory()->for($this->project)->create(['pipeline' => 'ai_sample', 'input' => ['cell_id' => $cell->id], 'cost_micros' => 0]);
        $step = PipelineStep::factory()->for($run, 'pipelineRun')->create(['step_key' => SampleAnswer::key(), 'cost_micros' => 0]);
        $cell->update(['pipeline_run_id' => $run->id]);
        AiSamplingAnswer::query()->create(['sampling_cell_id' => $cell->id, 'full_text' => 'Recorded response.', 'sections' => [], 'citations' => [], 'metadata' => [], 'content_hash' => str_repeat('d', 64), 'received_at' => now(), 'total_cost_micros' => $cost]);

        return [$run, $step];
    }

    /** @return array{StepContext,PipelineStep} */
    private function context(): array
    {
        $run = PipelineRun::factory()->for($this->project)->create(['pipeline' => 'ai_accuracy', 'price_list_version' => 1]);
        $step = PipelineStep::factory()->for($run, 'pipelineRun')->create(['step_key' => CheckAccuracy::key(), 'cost_micros' => 0]);

        return [new StepContext($run, $this->project, [], [], [], app(ModelGateway::class)), $step];
    }
}
