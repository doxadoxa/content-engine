<?php

declare(strict_types=1);

namespace App\Visibility\Sampling;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Enums\ProjectStatus;
use App\Models\AiAnswerReservation;
use App\Models\AiSamplingCycle;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** At most one batch for the current slot. Missed weeks are never backfilled. */
final readonly class SamplingSchedule
{
    public function __construct(private Entitlements $entitlements, private CurrentProject $current) {}

    public function dispatchDue(Project $project): ?AiSamplingRun
    {
        return $this->current->run($project, fn () => DB::transaction(function () use ($project): ?AiSamplingRun {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->entitlements->forget($project);
            $entitlement = $this->entitlements->for($project);
            $subscription = $entitlement->subscription;
            if ($entitlement->plan === null || $project->status !== ProjectStatus::Active || $entitlement->refusal(Metric::AiAnswers) !== null
                || $subscription === null || $subscription->period_ends_at?->isFuture() !== true || $subscription->periodStart()->isFuture()) {
                return null;
            }
            $days = $entitlement->limit('ai_frequency_days') ?? 30;
            $start = CarbonImmutable::instance($subscription->periodStart());
            // Monthly means the actual billing period, including short and long months.
            $slot = $this->slot($start, $days);
            $cycle = AiSamplingCycle::query()->firstOrCreate(['period_started_at' => $start, 'slot' => $slot], ['period_ends_at' => $subscription->period_ends_at]);
            if ($cycle->sampling_run_id !== null) {
                return AiSamplingRun::query()->findOrFail($cycle->sampling_run_id);
            }
            $set = app(SamplingSets::class)->forCycle($project);
            if ($set === null) {
                if ($cycle->pipeline_run_id === null) {
                    $run = app(PipelineRunner::class)->start('visibility', $project, ['sampling_cycle_id' => $cycle->id]);
                    $cycle->update(['pipeline_run_id' => $run->id]);
                }

                return null;
            }

            return $this->complete($project, $cycle, $set);
        }));
    }

    /** The original initialization pipeline may finish only its pinned, still-current slot. */
    public function finishInitialization(Project $project, string $cycleId): ?AiSamplingRun
    {
        return DB::transaction(function () use ($project, $cycleId): ?AiSamplingRun {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $cycle = AiSamplingCycle::query()->findOrFail($cycleId);
            if ($cycle->sampling_run_id !== null) {
                return AiSamplingRun::query()->findOrFail($cycle->sampling_run_id);
            }
            $set = app(SamplingSets::class)->forCycle($project, true);

            return $set === null ? null : $this->complete($project, $cycle, $set);
        });
    }

    /** @param array<string,mixed> $configuration */
    public function validate(Project $project, array $configuration): void
    {
        $this->entitlements->forget($project);
        $entitlement = $this->entitlements->for($project);
        if ($entitlement->plan === null) {
            return;
        }
        $questions = $entitlement->limit('ai_questions') ?? 0;
        if (count($configuration['prompts']) < 1 || count($configuration['prompts']) > $questions) {
            throw ValidationException::withMessages(['prompts' => "This plan supports up to {$questions} monitored questions. Save a question set within that allowance."]);
        }
        $platforms = array_column($configuration['panel'], 'platform');
        sort($platforms);
        if ($platforms !== ['chat_gpt', 'claude', 'gemini', 'perplexity']) {
            throw ValidationException::withMessages(['prompts' => 'The four supported AI services must be configured before this question set can run. Save a new question version after setup.']);
        }
    }

    /** @return array<string,mixed>|null */
    public function describe(Project $project): ?array
    {
        $this->entitlements->forget($project);
        $entitlement = $this->entitlements->for($project);
        if ($entitlement->plan === null) {
            return null;
        }
        $subscription = $entitlement->subscription;
        $start = $subscription === null ? null : CarbonImmutable::instance($subscription->periodStart());
        $days = $entitlement->limit('ai_frequency_days') ?? 30;
        $last = $start === null ? null : AiSamplingCycle::query()->where('period_started_at', $start)->whereNotNull('sampling_run_id')->orderByDesc('slot')->first();
        $next = $last === null ? $start : ($days >= 30 ? $subscription->period_ends_at : $start->addDays(($last->slot + 1) * $days));
        if ($next !== null && $subscription?->period_ends_at !== null && $next->greaterThan($subscription->period_ends_at)) {
            $next = $subscription->period_ends_at;
        }
        $reserved = $start === null ? 0 : AiAnswerReservation::query()->where('period_started_at', $start)->where('status', 'reserved')->count();
        $set = AiSamplingSet::query()->latest('version')->first();
        $batchSize = min(count($set?->configuration['prompts'] ?? []), $entitlement->limit('ai_questions') ?? 0) * 4;
        if ($batchSize > 0 && ! $entitlement->hasRoomFor(Metric::AiAnswers, $batchSize)) {
            $next = $subscription?->period_ends_at;
        }

        return ['frequency' => $days >= 30 ? 'monthly' : 'weekly', 'questions' => $entitlement->limit('ai_questions'), 'services' => 4,
            'used' => $entitlement->used(Metric::AiAnswers), 'reserved' => $reserved, 'limit' => $entitlement->limit('ai_answers'), 'remaining' => $entitlement->remaining(Metric::AiAnswers),
            'period_started_at' => $start?->toIso8601String(), 'period_ends_at' => $subscription?->period_ends_at?->toIso8601String(), 'next_check_at' => $next?->toIso8601String(),
            'available' => $entitlement->refusal(Metric::AiAnswers) === null && $project->status === ProjectStatus::Active && $subscription?->period_ends_at?->isFuture() === true,
            'note' => 'Scheduled checks, manual samples and rechecks share this allowance. Each service answer attempt uses one check, including paid failures or timeouts. Checks refused before a provider request release their reservation.'];
    }

    public function slot(CarbonImmutable $start, int $days): int
    {
        return $days >= 30 ? 0 : min(4, max(0, (int) floor($start->diffInSeconds(now()) / (max(1, $days) * 86400))));
    }

    private function complete(Project $project, AiSamplingCycle $cycle, AiSamplingSet $set): ?AiSamplingRun
    {
        $this->entitlements->forget($project);
        $entitlement = $this->entitlements->for($project);
        $subscription = $entitlement->subscription;
        if ($subscription === null || ! $cycle->period_started_at->equalTo($subscription->periodStart()) || ! $cycle->period_ends_at->isFuture()
            || $cycle->slot !== $this->slot($cycle->period_started_at, $entitlement->limit('ai_frequency_days') ?? 30)) {
            return null;
        }
        $run = app(SamplingRuns::class)->start($project, $set, 'scheduled:'.$cycle->id);
        $cycle->update(['sampling_run_id' => $run->id]);

        return $run;
    }
}
