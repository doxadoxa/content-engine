<?php

declare(strict_types=1);

namespace App\Visibility\Sampling;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Enums\ProjectStatus;
use App\Models\AiAnswerReservation;
use App\Models\AiSamplingCell;
use App\Models\Project;
use App\Models\ProjectSubscription;
use Illuminate\Support\Facades\DB;

/** Reserve before dispatch; only a refused request that never reached transport releases its unit. */
final readonly class AnswerAllowance
{
    public function __construct(private Entitlements $entitlements) {}

    public function applies(Project $project): bool
    {
        $this->entitlements->forget($project);

        return $this->entitlements->for($project)->plan !== null;
    }

    /** Caller holds the project lock; subscription lock serializes billing changes. */
    public function reserve(Project $project, AiSamplingCell $cell): void
    {
        if (! $this->applies($project)) {
            return;
        }
        $subscription = ProjectSubscription::query()->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
        $this->entitlements->forget($project);
        $refusal = $this->entitlements->for($project)->refusal(Metric::AiAnswers);
        abort_if($refusal !== null, 409, $refusal->message ?? 'No AI answer checks remain.');
        abort_unless($project->status === ProjectStatus::Active && $subscription->period_ends_at?->isFuture() === true && ! $subscription->periodStart()->isFuture(), 409, 'A current billing period is required for new AI checks.');
        abort_unless($this->entitlements->reserve($project, Metric::AiAnswers), 409, 'No AI answer checks remain in this billing period.');
        AiAnswerReservation::query()->create(['sampling_cell_id' => $cell->id, 'period_started_at' => $subscription->periodStart(), 'period_ends_at' => $subscription->period_ends_at, 'status' => 'reserved']);
    }

    /** Called under project then cell locks immediately before claiming transport. */
    public function attempt(Project $project, AiSamplingCell $cell): bool
    {
        $reservation = AiAnswerReservation::query()->where('sampling_cell_id', $cell->id)->lockForUpdate()->first();
        if ($reservation === null) {
            return false;
        }
        $subscription = ProjectSubscription::query()->where('project_id', $project->id)->lockForUpdate()->first();
        if ($reservation->status !== 'reserved' || $subscription === null || ! $reservation->period_started_at->equalTo($subscription->periodStart())
            || $reservation->period_ends_at->isPast() || $subscription->period_ends_at?->isFuture() !== true || $project->status !== ProjectStatus::Active) {
            $this->release($project, $cell);

            return false;
        }
        $reservation->update(['status' => 'attempted', 'attempted_at' => now()]);

        return true;
    }

    /** Does nothing once a paid request may have started. Safe for duplicate preflight failures. */
    public function release(Project $project, AiSamplingCell $cell): void
    {
        DB::transaction(function () use ($project, $cell): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $reservation = AiAnswerReservation::query()->where('sampling_cell_id', $cell->id)->lockForUpdate()->first();
            if ($reservation === null || $reservation->status !== 'reserved') {
                return;
            }
            DB::table('project_usage_periods')->where('project_id', $project->id)->where('period_started_at', $reservation->period_started_at)->where('metric', Metric::AiAnswers->value)->where('used', '>', 0)->decrement('used');
            $reservation->update(['status' => 'released', 'released_at' => now()]);
            $this->entitlements->forget($project);
        });
    }
}
