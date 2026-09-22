<?php

declare(strict_types=1);

namespace App\Facts;

use App\Ai\Contracts\ModelSession;
use App\Ai\ModelCatalog;
use App\Ai\ModelRequest;
use App\Ai\ModelResponse;
use App\Billing\Entitlements;
use App\Models\ProviderSpendRecord;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Steps\AiAccuracy\CheckAccuracy;
use App\Pipelines\Steps\FactMaintenance\CheckPageFacts;
use Throwable;

/** Survives a worker stopping between any checker call and the pipeline's aggregate write. */
final readonly class DurableFactSession implements ModelSession
{
    public function __construct(private StepContext $context, private ModelCatalog $catalog) {}

    public function send(ModelRequest $request): ModelResponse
    {
        $entitlements = app(Entitlements::class);
        $entitlements->forget($this->context->project);
        $refusal = $entitlements->for($this->context->project)->refusal();
        if ($refusal !== null) {
            throw new FactSpendingRefused($refusal->message);
        }
        $choice = $this->catalog->resolve($request->role);
        $record = ProviderSpendRecord::query()->create([
            'pipeline_run_id' => $this->context->run->id,
            'step_key' => $this->context->run->pipeline === 'fact_maintenance' ? CheckPageFacts::key() : CheckAccuracy::key(),
            'status' => 'pending', 'provider' => $choice->provider, 'model' => $choice->model, 'role' => $request->role,
            'price_list_version' => $this->context->run->price_list_version,
            'request_hash' => hash('sha256', json_encode([$request->role, $request->instructions, $request->prompt, $request->options], JSON_THROW_ON_ERROR)),
        ]);
        try {
            $response = $this->context->send($request);
        } catch (Throwable $error) {
            $record->update(['status' => 'unknown', 'cost_basis' => 'request_outcome_unknown', 'finished_at' => now()]);
            throw $error;
        }
        $prices = config('models.prices.versions.'.$record->price_list_version, []);
        $price = $prices[$response->model] ?? null;
        $priced = is_array($price) && isset($price['input'], $price['output']) && $response->totalTokens() > 0;
        $record->update(['status' => $priced ? 'reported' : 'unpriced', 'provider' => $response->provider, 'model' => $response->model,
            'response_hash' => hash('sha256', $response->text), 'input_tokens' => $response->inputTokens, 'output_tokens' => $response->outputTokens,
            'cost_micros' => $priced ? $this->catalog->cost($response->model, $response->inputTokens, $response->outputTokens, $record->price_list_version) : null,
            'cost_basis' => $priced ? 'reported_tokens_pinned_price' : ($response->totalTokens() === 0 ? 'usage_unavailable' : 'model_unpriced'), 'finished_at' => now()]);

        return $response;
    }

    public function spend(int $costMicros, ?string $provider, ?string $model): void
    {
        $this->context->spend($costMicros, $provider, $model);
    }
}
