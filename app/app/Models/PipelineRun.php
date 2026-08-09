<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Models\Concerns\BelongsToProject;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\ProjectScope;
use Carbon\CarbonInterface;
use Database\Factories\PipelineRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * One execution of one pipeline (§2, §6).
 *
 * The run row is a header: what was asked for, where it got to, and the totals.
 * The work and the money are on {@see PipelineStep}, and the totals here are
 * rolled up from those rows rather than accumulated as steps finish — parallel
 * branches finishing at once would race on an increment, and a sum of rows
 * cannot drift from the rows it sums.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $content_item_id
 * @property string $pipeline
 * @property int $pipeline_version
 * @property PipelineRunStatus $status
 * @property array<string, mixed> $input
 * @property array<string, mixed> $context
 * @property int $price_list_version
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micros
 * @property int|null $latency_ms
 * @property array<string, mixed>|null $error
 * @property string|null $failed_step_key
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class PipelineRun extends Model
{
    use BelongsToProject;

    /** @use HasFactory<PipelineRunFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'content_item_id',
        'pipeline',
        'pipeline_version',
        'status',
        'input',
        'context',
        'price_list_version',
        'input_tokens',
        'output_tokens',
        'cost_micros',
        'latency_ms',
        'error',
        'failed_step_key',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'input' => '{}',
        'context' => '{}',
    ];

    /**
     * @return HasMany<PipelineStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(PipelineStep::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<ContentItem, $this>
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Runs that have stopped moving and want looking at (`pipeline:reap`).
     *
     * A cheap candidate filter and nothing more. It cannot tell a dead run from
     * a slow one — that needs each step's own timeout, which is in the code and
     * not in this table — so it deliberately over-selects and leaves the
     * judgement to {@see PipelineRunner::resume()}, which
     * releases only genuinely stale claims and re-dispatches only what is
     * ready. Being picked up here is not an accusation.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStalled(Builder $query): void
    {
        $query->idleSince(now()->subSeconds((int) config('pipeline.stall_after', 900)));
    }

    /**
     * Runs the engine should still be waiting for.
     *
     * The complement of "wreckage", not the complement of {@see scopeStalled()}
     * — the two answer different questions and run against different
     * thresholds. A run is in flight until `abandon_after`, which is long
     * enough that the reaper has had many passes at it first. Anything past
     * that has been unreachable for hours: {@see
     * \App\Console\Commands\EngineTickCommand} must not keep a project's whole
     * article contour waiting on it, and the dashboard must not keep drawing it
     * as live work.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInFlight(Builder $query): void
    {
        $query
            ->whereIn('status', [PipelineRunStatus::Pending->value, PipelineRunStatus::Running->value])
            ->whereNot(fn (Builder $dead) => $dead->idleSince(
                now()->subSeconds((int) config('pipeline.abandon_after', 7200)),
            ));
    }

    /**
     * Non-terminal, and nothing has happened to it since `$cutoff`.
     *
     * "Something happened" is a step starting or finishing, or the run itself
     * being created or started — the four instants the engine writes as it
     * moves. A run whose newest one is old has nobody working on it and nothing
     * queued that arrived recently.
     *
     * The step half is a raw `exists` against the table rather than
     * `whereDoesntHave`, and that is not a micro-optimisation. The relation
     * would carry {@see ProjectScope} into the subquery,
     * which fails closed — so under `acrossProjects()` the inner query matches
     * nothing, "has no recent step" is true of everything, and a maintenance
     * command reaps every live run in the installation. Correlating on
     * `pipeline_run_id` is safe without the scope because a step cannot belong
     * to another tenant's run.
     *
     * @param  Builder<self>  $query
     */
    public function scopeIdleSince(Builder $query, CarbonInterface $cutoff): void
    {
        $query
            ->whereIn('status', [PipelineRunStatus::Pending->value, PipelineRunStatus::Running->value])
            ->where('created_at', '<=', $cutoff)
            ->where(fn (Builder $q) => $q->whereNull('started_at')->orWhere('started_at', '<=', $cutoff))
            ->whereNotExists(fn (QueryBuilder $steps) => $steps
                ->from('pipeline_steps')
                ->whereColumn('pipeline_steps.pipeline_run_id', 'pipeline_runs.id')
                ->where(fn (QueryBuilder $recent) => $recent
                    ->where('pipeline_steps.started_at', '>', $cutoff)
                    ->orWhere('pipeline_steps.finished_at', '>', $cutoff)));
    }

    /** Cost in dollars, for display only — never for arithmetic. */
    public function costInDollars(): float
    {
        return $this->cost_micros / 1_000_000;
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    /**
     * Recompute the header totals from the step rows.
     *
     * Latency is wall clock from first start to last finish, not the sum of the
     * steps: with parallel branches those differ, and what a run "took" is how
     * long somebody waited for it.
     */
    public function rollUpTotals(): void
    {
        /** @var Collection<int, PipelineStep> $steps */
        $steps = $this->steps()->get();

        $startedAt = $steps->min('started_at') ?? $this->started_at;
        $finishedAt = $steps->max('finished_at');

        $this->forceFill([
            'input_tokens' => (int) $steps->sum('input_tokens'),
            'output_tokens' => (int) $steps->sum('output_tokens'),
            'cost_micros' => (int) $steps->sum('cost_micros'),
            'latency_ms' => $startedAt !== null && $finishedAt !== null
                ? max(0, (int) Carbon::parse($startedAt)->diffInMilliseconds(Carbon::parse($finishedAt)))
                : $this->latency_ms,
        ])->save();
    }

    /** True once every step has settled one way or another. */
    public function allStepsSettled(): bool
    {
        return ! $this->steps()
            ->whereNotIn('status', [
                PipelineStepStatus::Succeeded->value,
                PipelineStepStatus::Skipped->value,
            ])
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PipelineRunStatus::class,
            'pipeline_version' => 'integer',
            'input' => 'array',
            'context' => 'array',
            'price_list_version' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micros' => 'integer',
            'latency_ms' => 'integer',
            'error' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
