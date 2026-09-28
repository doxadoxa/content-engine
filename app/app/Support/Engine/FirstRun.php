<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\BillingStatus;
use App\Enums\ContentItemState;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingRun;
use App\Models\ContentItem;
use App\Models\LlmVisibilityAnswer;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\SiteAudit;
use App\Onboarding\ProjectLaunch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A new project's first run, told as a journey rather than a list of jobs.
 *
 * Somebody who has just paid opens Home and sees what the engine has done so
 * far and what it is doing now: read the website, found topics, planned a
 * calendar, written articles, asked the assistants. Without this the first
 * screen they saw was a grid of dashes over a spinner below the fold, and a
 * paying customer read that as a product that had not started. The work was
 * happening; the page was not saying so.
 *
 * Its own class rather than more keys on {@see WorkInFlight}, because the two
 * answer different questions. That one lists runs — what is moving and what
 * broke, for as long as a project exists. This one reads *outcomes* — whether
 * each milestone of the first run has happened yet — and has nothing to say
 * once the first run is over. A run is not a milestone: three generation runs
 * are one step, and a site that was read in the wizard is a done step with no
 * run at all.
 *
 * **When it shows.** While the project is launching, always. Otherwise only
 * inside {@see WINDOW_HOURS} of the first run, and then only while something
 * on the journey is in flight, or while a step is still to come and a journey
 * run *completed* within {@see GAP_MINUTES} — the pause between one job ending
 * and the scheduler starting the next, which must not make the panel blink out
 * and back. A *failed* run does not hold the panel up: a chain that stopped is
 * not work in progress, and {@see WorkInFlight} is what says it stopped.
 *
 * Returns null when it should not show, which is the case for every project
 * past its first couple of days — and for those it costs two cheap queries.
 */
final class FirstRun
{
    /**
     * How long after the first run the journey may still show at all.
     *
     * A bound on a guess, not a schedule. A step that can never happen — no AI
     * questions, a research provider that keeps failing — must not keep a
     * panel saying "Avyo is working" on the landing screen for good. Two days
     * covers a launch, its first articles and the first AI check with room to
     * spare; after that, running work belongs to the ordinary run panel.
     */
    private const int WINDOW_HOURS = 48;

    /**
     * How long a completed step may be followed by nothing before the journey
     * is called over.
     *
     * Ninety minutes because the next job is often started by an hourly
     * command (`engine:tick`, `visibility:scheduled`), plus queue time. Any
     * shorter and the panel disappears between planning and the first AI
     * check, then reappears — which reads as a product that stopped and
     * restarted.
     */
    private const int GAP_MINUTES = 90;

    /**
     * The pipelines the journey is made of, by the step each one drives.
     *
     * `refresh` and `feedback` are not here: neither happens in a first run,
     * and both are routine work the run panel already describes.
     *
     * @var array<string, string>
     */
    private const array PIPELINES = [
        'site_audit' => 'site',
        'research' => 'topics',
        'planning' => 'calendar',
        'generation' => 'articles',
        'visibility' => 'ai',
        // One per sampled cell. A check on an existing sampling set runs only
        // these, with no `visibility` run in front of them.
        'ai_sample' => 'ai',
    ];

    /** @var list<ContentItemState> */
    private const array WRITTEN = [
        ContentItemState::Draft,
        ContentItemState::Approved,
        ContentItemState::Published,
        ContentItemState::Refreshing,
    ];

    public function __construct(private readonly ProjectLaunch $launch) {}

    /**
     * Keys are `steps`, each with `key`, `label`, `state` (`done`, `active` or
     * `upcoming`), `detail`, `subject` and `subject_id`; or null.
     *
     * Must run inside the project's scope, like everything else on Home.
     *
     * @return array<string, mixed>|null
     */
    public function for(Project $project): ?array
    {
        // The same repair {@see WorkInFlight} makes, and for the same reason:
        // a launch whose chain died would otherwise keep this panel up for
        // good. Idempotent, and one `exists` when there is anything to check.
        $this->launch->settleIfFinished($project);

        $launching = $project->onboarding_status === OnboardingStatus::Launching;

        if (! $launching && ! $this->withinWindow($project)) {
            return null;
        }

        $inFlight = PipelineRun::query()
            ->inFlight()
            ->whereIn('pipeline', array_keys(self::PIPELINES))
            ->with('contentItem:id,title')
            ->latest()
            ->get();

        // A sampling batch is the AI step's real work, and it runs outside any
        // pipeline once the questions exist. Bounded like `inFlight()`, so a
        // batch nobody closed stops counting as live.
        $sampling = AiSamplingRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>=', now()->subSeconds((int) config('pipeline.abandon_after', 7200)))
            ->exists();

        $completed = PipelineRun::query()
            ->where('status', PipelineRunStatus::Completed)
            ->whereIn('pipeline', array_keys(self::PIPELINES));

        $steps = $this->steps($project, $inFlight, $sampling, (clone $completed)->distinct()->pluck('pipeline')->all());

        $active = collect($steps)->contains('state', 'active');
        $finished = collect($steps)->every(static fn (array $step): bool => $step['state'] === 'done');

        $between = ! $finished && (clone $completed)
            ->where('finished_at', '>=', now()->subMinutes(self::GAP_MINUTES))
            ->exists();

        if (! $launching && ! $active && ! $between) {
            return null;
        }

        return ['steps' => $steps];
    }

    /**
     * Whether the first run started recently enough to still be the story.
     *
     * Measured from the project's first run rather than its creation, because
     * a project can sit in the wizard or wait for a card for days before the
     * engine does anything — and the journey is about the engine.
     *
     * Or from the start of a trial, when that is later. The card-free sample
     * runs first, and somebody who reads it on Monday and adds a card on
     * Thursday has a whole new first run ahead of them — the trial's articles,
     * the first AI check — which is exactly the work this panel exists to
     * show. Only a trial's start counts: an active plan's period start moves
     * every month, and a monthly renewal is not a first run.
     */
    private function withinWindow(Project $project): bool
    {
        $first = PipelineRun::query()->min('created_at');

        if ($first === null) {
            return false;
        }

        $since = Carbon::parse($first);

        $subscription = ProjectSubscription::query()
            ->where('project_id', $project->getKey())
            ->first(['status', 'period_started_at']);

        if ($subscription?->status === BillingStatus::Trialing
            && $subscription->period_started_at?->greaterThan($since) === true) {
            $since = $subscription->period_started_at;
        }

        return $since->greaterThanOrEqualTo(now()->subHours(self::WINDOW_HOURS));
    }

    /**
     * @param  Collection<int, PipelineRun>  $inFlight
     * @param  array<int, mixed>  $completed  the pipelines that have completed at least once
     * @return list<array<string, mixed>>
     */
    private function steps(Project $project, Collection $inFlight, bool $sampling, array $completed): array
    {
        $running = $inFlight->groupBy(static fn (PipelineRun $run): string => self::PIPELINES[$run->pipeline]);
        $isActive = static fn (string $step): bool => $running->has($step);
        $ran = static fn (string $pipeline): bool => in_array($pipeline, $completed, true);

        // One query for the three counts the article steps need. The project's
        // own language only: a unit written in three locales is one article to
        // the person reading this, and three would be a number that means
        // nothing to them.
        $counts = ContentItem::query()
            ->where('locale', $project->default_locale)
            ->selectRaw('count(*) as found, count(content_plan_id) as planned')
            ->selectRaw(
                'sum(case when state in ('.implode(',', array_fill(0, count(self::WRITTEN), '?')).') then 1 else 0 end) as written',
                array_map(static fn (ContentItemState $state): string => $state->value, self::WRITTEN),
            )
            ->toBase()
            ->first();

        $found = (int) ($counts->found ?? 0);
        $planned = (int) ($counts->planned ?? 0);
        $written = (int) ($counts->written ?? 0);

        $audit = SiteAudit::query()->whereNotNull('finished_at')->latest('finished_at')->first(['pages_crawled']);
        // The wizard reads the site before anything is launched, so most
        // projects arrive with this step already behind them. The crawl that
        // starts at launch is a deeper read of the same site, and shows as
        // active while it goes.
        $siteRead = $project->site_analysis !== [] || $audit !== null || $ran('site_audit');

        $answers = AiSamplingAnswer::query()->count();
        $asked = $answers > 0 || LlmVisibilityAnswer::query()->exists();

        $writing = $running->get('articles')?->first();

        return [
            $this->step(
                'site',
                'Reading your website',
                $isActive('site') ? 'active' : ($siteRead ? 'done' : 'upcoming'),
                match (true) {
                    $isActive('site') => 'Checking every page Avyo can find',
                    $audit !== null && $audit->pages_crawled > 0 => $this->count($audit->pages_crawled, 'page', 'pages').' read',
                    default => null,
                },
            ),
            $this->step(
                'topics',
                'Finding what your customers search for',
                $isActive('topics') ? 'active' : ($found > 0 || $ran('research') ? 'done' : 'upcoming'),
                match (true) {
                    $isActive('topics') => $found > 0
                        ? $this->count($found, 'topic', 'topics').' found so far'
                        : 'Looking at real searches in your market',
                    $found > 0 => $this->count($found, 'topic', 'topics').' found',
                    default => null,
                },
            ),
            $this->step(
                'calendar',
                'Planning your content calendar',
                $isActive('calendar') ? 'active' : ($planned > 0 || $ran('planning') ? 'done' : 'upcoming'),
                match (true) {
                    $planned > 0 => $this->count($planned, 'topic', 'topics').' planned',
                    $isActive('calendar') => 'Choosing what to write first',
                    default => null,
                },
            ),
            $this->step(
                'articles',
                'Writing your first articles',
                $isActive('articles') ? 'active' : ($written > 0 ? 'done' : 'upcoming'),
                match (true) {
                    $isActive('articles') => $written > 0 ? "{$written} written so far" : 'Drafting now',
                    $written > 0 => "{$written} written",
                    default => null,
                },
                $writing?->contentItem?->title,
                $writing?->contentItem?->getKey(),
            ),
            $this->step(
                'ai',
                'Asking ChatGPT, Gemini, Claude and Perplexity about you',
                $isActive('ai') || $sampling ? 'active' : ($asked ? 'done' : 'upcoming'),
                match (true) {
                    ($isActive('ai') || $sampling) && $answers > 0 => $this->count($answers, 'answer', 'answers').' so far',
                    $isActive('ai') || $sampling => 'Asking the questions your customers ask',
                    $answers > 0 => $this->count($answers, 'answer', 'answers').' read',
                    default => null,
                },
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function step(
        string $key,
        string $label,
        string $state,
        ?string $detail,
        ?string $subject = null,
        ?string $subjectId = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'detail' => $detail,
            'subject' => $subject,
            'subject_id' => $subjectId,
        ];
    }

    private function count(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }
}
