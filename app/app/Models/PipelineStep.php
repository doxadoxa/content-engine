<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PipelineStepStatus;
use App\Models\Concerns\BelongsToProject;
use App\Pipelines\Core\PipelineRunner;
use Database\Factories\PipelineStepFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of one run: its state, its attempts, and what it cost (§3.4).
 *
 * `attempt` is load-bearing beyond counting. It is the optimistic-lock version
 * every write to this row is guarded by, which is what stops two deliveries of
 * the same step from both executing it — see {@see PipelineRunner}.
 *
 * @property string $id
 * @property string $project_id
 * @property string $pipeline_run_id
 * @property string $step_key
 * @property int $position
 * @property PipelineStepStatus $status
 * @property int $attempt
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $role
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micros
 * @property int|null $latency_ms
 * @property array<string, mixed>|null $output
 * @property array<string, mixed>|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class PipelineStep extends Model
{
    use BelongsToProject;

    /** @use HasFactory<PipelineStepFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'pipeline_run_id',
        'step_key',
        'position',
        'status',
        'attempt',
        'provider',
        'model',
        'role',
        'input_tokens',
        'output_tokens',
        'cost_micros',
        'latency_ms',
        'output',
        'error',
        'started_at',
        'finished_at',
    ];

    /**
     * @return BelongsTo<PipelineRun, $this>
     */
    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    /** Cost in dollars, for display only — never for arithmetic. */
    public function costInDollars(): float
    {
        return $this->cost_micros / 1_000_000;
    }

    /**
     * Whether this attempt has been running longer than the step is allowed to,
     * plus a grace period — the signal that a worker died holding it.
     */
    public function isStale(int $timeoutSeconds): bool
    {
        if ($this->status !== PipelineStepStatus::Running || $this->started_at === null) {
            return false;
        }

        $grace = (int) config('pipeline.stale_claim_grace', 60);

        return $this->started_at->lessThan(now()->subSeconds($timeoutSeconds + $grace));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PipelineStepStatus::class,
            'position' => 'integer',
            'attempt' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micros' => 'integer',
            'latency_ms' => 'integer',
            'output' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
