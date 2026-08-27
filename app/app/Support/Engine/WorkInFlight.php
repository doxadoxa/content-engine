<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Onboarding\ProjectLaunch;
use Illuminate\Support\Carbon;

/**
 * What the engine is doing right now, and what it stopped doing today.
 *
 * **The first hour of a project.** A project set up ten minutes ago has no
 * articles and no impressions, and a grid of zeroes reads as a broken product
 * rather than a young one. What it does have is work in flight — so this is
 * what the top of the landing screen is until the work stops, and it returns
 * nothing at all once it has.
 *
 * Extracted from `DashboardController` when the dashboard stopped being a
 * screen. Nothing here changed in the move: the value of these two queries is
 * entirely in the corrections already baked into them, each of which is a bug
 * that reached an operator.
 */
final class WorkInFlight
{
    public function __construct(private readonly ProjectLaunch $launch) {}

    /**
     * Keys are `launching`, `active` and `failed`.
     *
     * Loosely typed on purpose: a precise `list<...>` shape here cannot be
     * proven, because an Eloquent collection's `map()->values()->all()` is not
     * a list as far as static analysis is concerned, and the shape is asserted
     * where it matters — in the tests that read these props off the page.
     *
     * @return array<string, mixed>
     */
    public function for(Project $project): array
    {
        // A launch whose chain died — worker killed, queue drained, release
        // deployed mid-run — would otherwise show a spinner forever. Checked
        // here because this is the page somebody is staring at while it does.
        $this->launch->settleIfFinished($project);

        // `inFlight()` rather than "not terminal", because a status column can
        // say `running` about a run nothing is running. This drew a spinner at
        // "2 of 3" for two days over a `visibility` run whose third step was
        // dispatched to a worker that could not build the pipeline — the
        // failure handler threw the same error the step did, so nothing ever
        // wrote a terminal status. Both halves of that are fixed, but this
        // should not be the thing that depends on them: a live badge is a claim
        // about now, and it has to expire.
        $active = PipelineRun::query()
            ->inFlight()
            ->with(['steps', 'contentItem:id,title'])
            ->latest()
            ->limit(12)
            ->get();

        // A failure the same pipeline has since recovered from is history, not
        // news. Leaving it up means an operator who has fixed the cause still
        // sees the old error every time they open the page, and stops reading
        // the panel — which is the failure mode this exists to avoid.
        $recovered = PipelineRun::query()
            ->where('status', PipelineRunStatus::Completed)
            ->selectRaw('pipeline, max(finished_at) as recovered_at')
            ->groupBy('pipeline')
            ->pluck('recovered_at', 'pipeline');

        $recentlyFailed = PipelineRun::query()
            ->where('status', PipelineRunStatus::Failed)
            ->where('finished_at', '>=', now()->subDay())
            ->with('contentItem:id,title')
            ->latest('finished_at')
            ->limit(20)
            ->get()
            ->reject(static function (PipelineRun $run) use ($recovered): bool {
                $since = $recovered[$run->pipeline] ?? null;

                return $since !== null
                    && $run->finished_at !== null
                    && Carbon::parse((string) $since)->greaterThan($run->finished_at);
            })
            ->take(5)
            ->values();

        return [
            'launching' => $project->onboarding_status === OnboardingStatus::Launching,
            'active' => $active->map(static fn (PipelineRun $run): array => [
                'id' => $run->getKey(),
                'pipeline' => $run->pipeline,
                // What the run is actually doing, for the pipelines that carry
                // more than one job. `content_studio` carries six, and this
                // labelled all of them "Proposing the social content system" —
                // so eighteen posts being drafted read as eighteen proposals of
                // the same thing.
                'action' => self::actionOf($run),
                'status' => $run->status->value,
                'subject' => $run->contentItem?->title,
                // The route into the thing being made. Without it the panel can
                // say work is happening and give no way to reach it, which
                // leaves somebody watching a bar with nowhere to go.
                'subject_id' => $run->contentItem?->getKey(),
                'started_at' => $run->started_at?->toIso8601String(),
                'total_steps' => $run->steps->count(),
                // Skipped counts as done. A step that had nothing to do is not
                // something the operator is still waiting on, and leaving it
                // out makes a finished run read as stuck at 9 of 11.
                'done_steps' => $run->steps->whereIn('status', [
                    PipelineStepStatus::Succeeded,
                    PipelineStepStatus::Skipped,
                ])->count(),
                'current_step' => $run->steps
                    ->firstWhere('status', PipelineStepStatus::Running)?->step_key,
            ])->values()->all(),
            'failed' => $recentlyFailed->map(static fn (PipelineRun $run): array => [
                'id' => $run->getKey(),
                'pipeline' => $run->pipeline,
                'action' => self::actionOf($run),
                'subject' => $run->contentItem?->title,
                'step' => $run->failed_step_key,
                'message' => is_string($run->error['message'] ?? null)
                    ? $run->error['message']
                    : null,
            ])->values()->all(),
        ];
    }

    private static function actionOf(PipelineRun $run): ?string
    {
        $action = $run->input['action'] ?? null;

        return is_string($action) && $action !== '' ? $action : null;
    }
}
