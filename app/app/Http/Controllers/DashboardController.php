<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContentItemState;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Integrations\Google\GooglePanel;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\User;
use App\Onboarding\ProjectLaunch;
use App\Support\Health\StackHealth;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\VisibilityReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the engine is doing for this project, right now.
 *
 * A freshly onboarded project has nothing to report for the first hour, and the
 * honest thing to show then is the work in progress rather than a grid of
 * zeroes — an operator who has just handed us a URL wants to see that research
 * is running, not that they have published nothing.
 *
 * So the page has two faces off the same props: while runs are in flight it is
 * a progress view that polls, and once they settle it is the numbers. Both are
 * built from the same query, because "is anything running" is the only thing
 * that distinguishes them.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly GooglePanel $google,
        private readonly ProjectLaunch $launch,
    ) {}

    public function __invoke(Request $request, CurrentProject $current, StackHealth $health): Response
    {
        /** @var User $user */
        $user = $request->user();

        $project = $current->get();

        if ($project === null) {
            return Inertia::render('dashboard', [
                'project' => null,
                'hasProjects' => $user->projects()->exists(),
                'health' => Inertia::defer(fn (): array => $health->check()),
            ]);
        }

        return Inertia::render('dashboard', [
            'project' => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'slug' => $project->slug,
                'website_url' => $project->website_url,
                'onboarding_status' => $project->onboarding_status->value,
                'weekly_target' => $project->weekly_target,
                'is_ymyl' => $project->is_ymyl,
            ],
            'hasProjects' => true,

            // Not deferred: on a launching project this *is* the page, and
            // deferring it would show an empty frame for one round trip before
            // the only content anybody came for.
            'work' => $this->work($project),

            'stats' => Inertia::defer(fn (): array => $this->stats()),

            // Which halves of Google this project has. Without it the empty
            // cards below say "—" forever, and an operator cannot tell a
            // project with no traffic from one that was never connected.
            'connected' => Inertia::defer(fn (): array => $this->google->connectionState($project)),
            'upcoming' => Inertia::defer(fn (): array => $this->upcoming($project->default_locale)),
            'recent' => Inertia::defer(fn (): array => $this->recent($project->default_locale)),
            'health' => Inertia::defer(fn (): array => $health->check()),

            // Deferred: reading the last sweep is a couple of queries and
            // nobody lands on the dashboard for this card first.
            'visibility' => Inertia::defer(fn (): array => $this->visibility()),
        ]);
    }

    /**
     * The headline from the last assistant sweep.
     *
     * Read through {@see VisibilityReport}, the same object the analysis screen
     * and the pipeline's own summary use, so the three cannot disagree about a
     * percentage the operator sees in all three places.
     *
     * @return array<string, mixed>
     */
    private function visibility(): array
    {
        $report = VisibilityReport::latest();

        return [
            // Null rather than 0 all the way to the component. "You are in none
            // of the answers" and "nothing has been asked yet" are opposite
            // facts that both render as 0% if the null is coerced away here.
            'score' => $report->score(),
            'monitored_prompts' => $report->monitoredPrompts(),
            'mentions' => $report->mentions(),
            'answered' => $report->answered(),
            'last_asked_on' => $report->lastAskedOn?->toDateString(),
            'by_locale' => $report->byLocale(),
        ];
    }

    /**
     * Runs that are still going, and how far through each one is.
     *
     * Step counts rather than a percentage: a run's steps are not equal in
     * length, so a bar drawn from them would move in lies. "4 of 11, currently
     * drafting" is both true and more use.
     *
     * @return array<string, mixed>
     */
    private function work(Project $project): array
    {
        // A launch whose chain died — worker killed, queue drained, release
        // deployed mid-run — would otherwise show a spinner forever. Checked
        // here because this is the page somebody is staring at while it does.
        $this->launch->settleIfFinished($project);

        // `inFlight()` rather than "not terminal", because a status column can
        // say `running` about a run nothing is running. This card drew a
        // spinner at "2 of 3" for two days over a `visibility` run whose third
        // step was dispatched to a worker that could not build the pipeline —
        // the failure handler threw the same error the step did, so nothing
        // ever wrote a terminal status. Both halves of that are fixed, but the
        // card should not be the thing that depends on them: a live badge is a
        // claim about now, and it has to expire.
        $active = PipelineRun::query()
            ->inFlight()
            ->with(['steps', 'contentItem:id,title'])
            ->latest()
            ->limit(12)
            ->get();

        // A failure the same pipeline has since recovered from is history, not
        // news. Leaving it up means an operator who has fixed the cause still
        // sees the old error every time they open the page, and stops reading
        // the panel — which is the failure mode this card exists to avoid.
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
            ->reject(function (PipelineRun $run) use ($recovered): bool {
                $since = $recovered[$run->pipeline] ?? null;

                return $since !== null
                    && $run->finished_at !== null
                    && Carbon::parse((string) $since)->greaterThan($run->finished_at);
            })
            ->take(5)
            ->values();

        return [
            'launching' => $project->onboarding_status === OnboardingStatus::Launching,
            'active' => $active->map(fn (PipelineRun $run): array => [
                'id' => $run->getKey(),
                'pipeline' => $run->pipeline,
                'status' => $run->status->value,
                'subject' => $run->contentItem?->title,
                'started_at' => $run->started_at?->toIso8601String(),
                'total_steps' => $run->steps->count(),
                // Skipped counts as done. A step that had nothing to do is
                // not something the operator is still waiting on, and leaving
                // it out makes a finished run read as stuck at 9 of 11.
                'done_steps' => $run->steps->whereIn('status', [
                    PipelineStepStatus::Succeeded,
                    PipelineStepStatus::Skipped,
                ])->count(),
                'current_step' => $run->steps
                    ->firstWhere('status', PipelineStepStatus::Running)?->step_key,
            ])->all(),
            'failed' => $recentlyFailed->map(fn (PipelineRun $run): array => [
                'id' => $run->getKey(),
                'pipeline' => $run->pipeline,
                'subject' => $run->contentItem?->title,
                'step' => $run->failed_step_key,
                'message' => is_string($run->error['message'] ?? null)
                    ? $run->error['message']
                    : null,
            ])->all(),
        ];
    }

    /**
     * The four numbers this engine can actually stand behind.
     *
     * Backlinks, page speed and session counts are not here because nothing in
     * this system measures them; a card reading "—" forever is worse than a
     * card that does not exist.
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $roots = fn (): Builder => ContentItem::query()->roots();

        $published = (clone $roots())->inState(ContentItemState::Published)->count();
        $planned = (clone $roots())->inState(ContentItemState::Idea)->count();
        $drafts = (clone $roots())->inState(ContentItemState::Draft)->count();
        $awaiting = (clone $roots())->inState(ContentItemState::Approved)->count();

        // Targeted volume: what the plan is aiming at, not what it has won.
        // Named on the card accordingly — the two get confused constantly.
        $targetedVolume = (int) (clone $roots())->sum('topic_volume');

        $checked = (clone $roots())->whereNotNull('citations_checked_at');
        $checkedCount = $checked->clone()->count();
        $citedCount = $checked->clone()->withCitation()->count();

        $since = now()->subDays(28)->toDateString();

        /** @var object{impressions: int|null, clicks: int|null, sessions: int|null, engaged: int|null}|null $search */
        $search = ContentMetric::query()
            ->where('measured_on', '>=', $since)
            ->selectRaw(
                'sum(impressions) as impressions, sum(clicks) as clicks, '
                .'sum(sessions) as sessions, sum(engaged_sessions) as engaged'
            )
            ->first();

        return [
            'published' => $published,
            'planned' => $planned,
            'drafts' => $drafts,
            'awaiting_approval' => $awaiting,
            'targeted_volume' => $targetedVolume,
            'citations' => [
                'checked' => $checkedCount,
                'cited' => $citedCount,
            ],
            'search' => [
                'impressions' => (int) ($search->impressions ?? 0),
                'clicks' => (int) ($search->clicks ?? 0),
                'days' => 28,
            ],
            'engagement' => [
                'sessions' => (int) ($search->sessions ?? 0),
                'engaged' => (int) ($search->engaged ?? 0),
                'days' => 28,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcoming(string $defaultLocale): array
    {
        $states = [
            ContentItemState::Idea->value,
            ContentItemState::Queued->value,
            ContentItemState::Generating->value,
        ];

        $groupIds = ContentItem::query()
            ->roots()
            ->whereIn('state', $states)
            ->whereNotNull('scheduled_for')
            ->select('locale_group_id')
            ->selectRaw('min(scheduled_for) as next_date')
            ->groupBy('locale_group_id')
            ->orderBy('next_date')
            ->limit(6)
            ->pluck('locale_group_id');

        $groups = ContentItem::query()
            ->roots()
            ->whereIn('state', $states)
            ->whereIn('locale_group_id', $groupIds)
            ->get(['id', 'locale_group_id', 'locale', 'title', 'state', 'scheduled_for', 'target_query', 'topic_volume'])
            ->groupBy('locale_group_id');

        return array_values($groupIds->map(function (string $groupId) use ($groups, $defaultLocale): array {
            $group = $groups->get($groupId);
            /** @var ContentItem $item */
            $item = $group?->firstWhere('locale', $defaultLocale) ?? $group?->first();

            return [
                'id' => $item->getKey(),
                'title' => $item->title,
                'state' => $item->state->value,
                'scheduled_for' => $item->scheduled_for?->toDateString(),
                'target_query' => $item->target_query,
                'topic_volume' => $item->topic_volume,
                'locales' => $group?->pluck('locale')->unique()->sort()->values()->all() ?? [$item->locale],
            ];
        })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recent(string $defaultLocale): array
    {
        $states = [
            ContentItemState::Draft->value,
            ContentItemState::Approved->value,
            ContentItemState::Published->value,
        ];

        $groupIds = ContentItem::query()
            ->roots()
            ->whereIn('state', $states)
            ->select('locale_group_id')
            ->selectRaw('max(updated_at) as latest_update')
            ->groupBy('locale_group_id')
            ->orderByDesc('latest_update')
            ->limit(6)
            ->pluck('locale_group_id');

        $groups = ContentItem::query()
            ->roots()
            ->whereIn('state', $states)
            ->whereIn('locale_group_id', $groupIds)
            ->get(['id', 'locale_group_id', 'locale', 'title', 'state', 'updated_at', 'public_url'])
            ->groupBy('locale_group_id');

        return array_values($groupIds->map(function (string $groupId) use ($groups, $defaultLocale): array {
            $group = $groups->get($groupId);
            /** @var ContentItem $item */
            $item = $group?->firstWhere('locale', $defaultLocale) ?? $group?->first();

            return [
                'id' => $item->getKey(),
                'title' => $item->title,
                'state' => $item->state->value,
                'updated_at' => $item->updated_at?->toIso8601String(),
                'public_url' => $item->public_url,
                'locales' => $group?->pluck('locale')->unique()->sort()->values()->all() ?? [$item->locale],
            ];
        })->all());
    }
}
