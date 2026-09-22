<?php

declare(strict_types=1);

namespace App\Visibility\Sampling;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Enums\ProjectStatus;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Models\Project;
use App\Models\User;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;

final class SamplingRuns
{
    public function __construct(private readonly CurrentProject $current) {}

    /** @param list<string>|null $onlyCellKeys */
    public function start(Project $project, AiSamplingSet $set, string $requestKey, ?User $actor = null, ?AiSamplingRun $recheck = null, ?array $onlyCellKeys = null): AiSamplingRun
    {
        abort_unless($set->project_id === $project->id && ($recheck === null || $recheck->project_id === $project->id), 404);

        return $this->current->run($project, fn (): AiSamplingRun => DB::transaction(function () use ($project, $set, $requestKey, $actor, $recheck, $onlyCellKeys): AiSamplingRun {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $existing = AiSamplingRun::query()->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                abort_unless($existing->sampling_set_id === $set->id && $existing->recheck_of_run_id === $recheck?->id, 409, 'This submission identity belongs to a different sampling request.');
                if ($onlyCellKeys !== null) {
                    $priorKeys = AiSamplingCell::query()->where('sampling_run_id', $existing->id)->pluck('cell_key')->all();
                    abort_unless(count($priorKeys) === count($onlyCellKeys) && array_diff($priorKeys, $onlyCellKeys) === [], 409, 'This submission identity belongs to different questions.');
                }

                return $existing;
            }
            $entitlements = app(Entitlements::class);
            $entitlements->forget($project);
            $refusal = $entitlements->for($project)->refusal();
            abort_if($refusal !== null, 409, $refusal->message ?? 'Paid work is unavailable.');
            abort_if($actor !== null && ! $actor->projects()->whereKey($project->id)->wherePivot('role', 'owner')->exists(), 403);
            $limited = app(AnswerAllowance::class)->applies($project);
            if ($limited) {
                abort_unless($project->status === ProjectStatus::Active, 409, 'Resume this project before starting AI checks.');
                app(SamplingSchedule::class)->validate($project, $set->configuration);
                $wanted = $onlyCellKeys === null ? count($set->configuration['prompts']) * count($set->configuration['panel']) : count(array_unique($onlyCellKeys));
                abort_unless($wanted > 0 && $entitlements->for($project)->hasRoomFor(Metric::AiAnswers, $wanted), 409, 'This collection exceeds the remaining AI answer checks for this billing period. A single-question recheck uses one check.');
            }
            $run = AiSamplingRun::query()->create(['sampling_set_id' => $set->id, 'request_key' => $requestKey,
                'purpose' => $recheck === null ? 'scheduled_or_manual' : 'recheck', 'requested_by' => $actor?->id, 'recheck_of_run_id' => $recheck?->id, 'status' => 'queued']);
            $count = 0;
            foreach ($set->configuration['prompts'] as $prompt) {
                foreach ($set->configuration['panel'] as $platform) {
                    $key = hash('sha256', $prompt['key'].'|'.$platform['platform']);
                    if ($onlyCellKeys !== null && ! in_array($key, $onlyCellKeys, true)) {
                        continue;
                    }
                    $allowed = $count++ < min((int) $set->configuration['max_answers'], (int) config('visibility.max_answers_per_run', 80));
                    $cell = AiSamplingCell::query()->create(['sampling_run_id' => $run->id, 'cell_key' => $key,
                        'specification' => ['prompt' => $prompt, 'platform' => $platform, 'market' => $set->configuration['market'], 'brand' => $set->configuration['brand'], 'website' => $set->configuration['website']],
                        'status' => $allowed ? 'queued' : 'budget_skipped', 'reason' => $allowed ? null : 'The configured answer limit excluded this cell.', 'finished_at' => $allowed ? null : now()]);
                    if ($allowed) {
                        app(AnswerAllowance::class)->reserve($project, $cell);
                    }
                }
            }
            abort_unless($count > 0, 422, 'No questions match this collection.');
            DB::afterCommit(fn () => $this->dispatch($project, $run));

            return $run;
        }));
    }

    public function dispatch(Project $project, AiSamplingRun $run): void
    {
        $this->current->run($project, function () use ($project, $run): void {
            foreach (AiSamplingCell::query()->where('sampling_run_id', $run->id)->where('status', 'queued')->whereNull('pipeline_run_id')->get() as $cell) {
                $pipeline = app(PipelineRunner::class)->start('ai_sample', $project, ['cell_id' => $cell->id]);
                AiSamplingCell::query()->whereKey($cell->id)->whereNull('pipeline_run_id')->update(['pipeline_run_id' => $pipeline->id]);
            }
            $this->finish($run->id);
        });
    }

    public function finish(string $runId): void
    {
        $statuses = AiSamplingCell::query()->where('sampling_run_id', $runId)->pluck('status');
        if ($statuses->contains(fn ($status): bool => in_array($status, ['queued', 'running'], true))) {
            AiSamplingRun::query()->whereKey($runId)->update(['status' => 'running']);

            return;
        }
        $complete = $statuses->isNotEmpty() && $statuses->every(fn ($status): bool => in_array($status, ['answered', 'empty'], true));
        AiSamplingRun::query()->whereKey($runId)->update(['status' => $complete ? 'complete' : 'partial', 'finished_at' => now()]);
    }
}
