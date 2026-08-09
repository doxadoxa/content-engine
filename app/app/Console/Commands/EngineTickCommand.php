<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ArticleScore;
use App\Enums\ContentItemState;
use App\Enums\ProjectStatus;
use App\Media\HeroImage;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan engine:tick` — the thing that makes this a service.
 *
 * Every pipeline in this application was startable by hand and started by
 * nothing. A project could be onboarded, planned and left: the calendar filled
 * up with dates nobody would ever write, no social post was ever cut, and
 * nothing published. "Set it up and it runs" was true of every part except the
 * part that runs it.
 *
 * One command rather than six schedule entries, because the decisions are
 * conditional on each other — there is no point planning a month from an empty
 * idea pool, or drafting a unit whose plan was never approved — and a crontab
 * cannot express that.
 *
 * Everything here is idempotent and bounded. It runs often, and a tick that
 * overlaps the previous one must not start the same work twice.
 */
class EngineTickCommand extends Command
{
    /**
     * How far ahead to draft: one day.
     *
     * An article due tomorrow is written today, so there is an evening to read
     * it in before it goes. Further ahead than that and a reviewer is checking
     * work that will sit for a week before anybody sees it.
     */
    private const int DRAFT_LEAD_DAYS = 1;

    /**
     * How many days before a month begins to plan it.
     *
     * The plan is a month of dates and it has to exist before the month does —
     * otherwise the 1st arrives with nothing scheduled on it and the first
     * article of the month is written late.
     */
    private const int PLAN_AHEAD_DAYS = 5;

    /** Ceiling per tick per project, so one busy project cannot flood the queue. */
    private const int MAX_STARTS = 5;

    /**
     * The pipelines this command starts, and therefore the only ones allowed to
     * stop it ({@see isBusy()}).
     *
     * Every entry here is something {@see due()} can return. Written as a list
     * rather than derived, because it is a *rule* — "the article contour waits
     * for the article contour" — and a derived set would silently grow the day
     * somebody starts a social pipeline from here.
     */
    private const array CONTOUR = [
        'research',
        'planning',
        'generation',
        'repurpose',
        'feedback',
        'visibility',
    ];

    protected $signature = 'engine:tick
        {--project= : Only this project, by slug or id}
        {--dry : Say what would start, start nothing}';

    protected $description = 'Start whatever each project is due: research, planning, drafting, social, publishing';

    public function __construct(private readonly ArticleScore $score)
    {
        parent::__construct();
    }

    public function handle(PipelineRunner $runner, CurrentProject $current): int
    {
        $projects = $this->projects();

        if ($projects->isEmpty()) {
            $this->components->info('No active projects.');

            return self::SUCCESS;
        }

        foreach ($projects as $project) {
            $current->run($project, function () use ($project, $runner): void {
                $this->tick($project, $runner);
            });
        }

        return self::SUCCESS;
    }

    private function tick(Project $project, PipelineRunner $runner): void
    {
        // Nothing else while the last thing in *this* contour is still going.
        // The six pipelines here feed each other, and starting planning while
        // research is still filling the pool plans a month from half of it.
        // Listening does not feed them and does not stop them — see
        // {@see isBusy()}.
        if ($this->isBusy($project)) {
            $this->line("  {$project->slug}: still working, nothing started");

            return;
        }

        $approved = $this->option('dry') ? 0 : $this->approveWhatMayGo($project);

        if ($approved > 0) {
            $this->line("  {$project->slug}: approved {$approved} draft(s) — this project publishes without review");
        }

        $started = 0;

        foreach ($this->due($project) as $work) {
            if ($started >= self::MAX_STARTS) {
                break;
            }

            $this->line("  {$project->slug}: {$work['why']}");

            if ($this->option('dry')) {
                $started++;

                continue;
            }

            try {
                $runner->start(
                    $work['pipeline'],
                    $project,
                    // Planning the month ahead has to say which month; every
                    // other pipeline takes nothing.
                    isset($work['month']) ? ['month' => $work['month']] : [],
                    $work['unit'] ?? null,
                );
                $started++;
            } catch (Throwable $e) {
                // One project's bad state must not stop the tick for the rest.
                Log::warning('A scheduled pipeline could not start', [
                    'project' => $project->slug,
                    'pipeline' => $work['pipeline'],
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        if ($started === 0) {
            $this->line("  {$project->slug}: up to date");
        }
    }

    /**
     * What this project is due, most urgent first.
     *
     * Order matters and it is by *deadline*, not by pipeline order. Topping up
     * the idea pool used to come first, which meant a unit due tomorrow waited
     * behind research it did not need — the calendar had a date on it and
     * nothing was writing.
     *
     * @return list<array{pipeline: string, why: string, unit?: string, month?: string}>
     */
    private function due(Project $project): array
    {
        $work = [];

        // 1. Anything with a date close enough to need writing. The lead time
        //    is what gives a human room to approve before the day it is due.
        //
        //    Unbounded on purpose where every other query here is capped: what
        //    it selects is "dated, and nobody has written it" — a set the plan
        //    itself bounds, and one that cannot grow large unless the engine has
        //    already stopped, which is the case that most needs to see all of
        //    it. The cap goes on {@see leadLocales()} instead, where it can be
        //    counted in units rather than in rows.
        $toDraft = ContentItem::query()
            ->roots()
            ->inState(ContentItemState::Idea)
            ->whereNotNull('content_plan_id')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', Carbon::today()->addDays(self::DRAFT_LEAD_DAYS)->toDateString())
            ->orderBy('scheduled_for')
            ->get();

        foreach ($this->leadLocales($toDraft, $project) as $unit) {
            $work[] = [
                'pipeline' => 'generation',
                'why' => "writing “{$unit->title}”, due {$unit->scheduled_for?->toDateString()}",
                'unit' => $unit->getKey(),
            ];
        }

        // 2. Social, for published articles that were promised derivatives and
        //    have none. Published only: a social post links to the article, and
        //    repurpose refuses a draft outright.
        $toRepurpose = ContentItem::query()
            ->roots()
            ->inState(ContentItemState::Published)
            ->whereNotNull('body_markdown')
            ->whereDoesntHave('derivatives')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_STARTS)
            ->get()
            ->filter(static fn (ContentItem $unit): bool => $unit->planned_derivatives !== []);

        foreach ($toRepurpose as $unit) {
            $work[] = [
                'pipeline' => 'repurpose',
                'why' => "cutting “{$unit->title}” down for social",
                'unit' => $unit->getKey(),
            ];
        }

        if ($work !== []) {
            return $work;
        }

        // Nothing is due today, so the rest is about not running out.
        $spareIdeas = ContentItem::query()
            ->roots()
            ->inState(ContentItemState::Idea)
            ->whereNull('content_plan_id')
            ->count();

        $plannedAhead = ContentItem::query()
            ->roots()
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '>=', Carbon::today()->toDateString())
            ->count();

        // 3a. Next month, a few days before it starts. Waiting until the 1st
        //     means the 1st has nothing on it.
        $nextMonth = Carbon::today()->addMonth()->startOfMonth();
        $monthEndsSoon = Carbon::today()->diffInDays(Carbon::today()->endOfMonth()) <= self::PLAN_AHEAD_DAYS;

        $nextMonthPlanned = ContentItem::query()
            ->roots()
            ->whereBetween('scheduled_for', [
                $nextMonth->toDateString(),
                $nextMonth->copy()->endOfMonth()->toDateString(),
            ])
            ->exists();

        if ($monthEndsSoon && ! $nextMonthPlanned && $spareIdeas > 0 && ! $this->ranWithin($project, 'planning', 12)) {
            return [[
                'pipeline' => 'planning',
                'why' => 'next month starts soon and has nothing on it, planning it now',
                'month' => $nextMonth->toDateString(),
            ]];
        }

        // 3b. Planning, when the calendar is running out and there are ideas to
        //     plan from. Planning an empty pool produces an empty month.
        if ($plannedAhead < $project->weekly_target && $spareIdeas > 0 && ! $this->ranWithin($project, 'planning', 12)) {
            return [['pipeline' => 'planning', 'why' => 'calendar is running out, planning ahead']];
        }

        // 4. Research, when the pool cannot feed the months to come.
        if ($spareIdeas < $project->weekly_target * 2 && ! $this->ranWithin($project, 'research', 6)) {
            return [['pipeline' => 'research', 'why' => 'idea pool is thin, researching']];
        }

        // 5. Feedback, weekly. Only worth running once something is live.
        $hasLive = ContentItem::query()->roots()->inState(ContentItemState::Published)->exists();

        if ($hasLive && ! $this->ranWithin($project, 'feedback', 24 * 7)) {
            return [['pipeline' => 'feedback', 'why' => 'reading what the published work did']];
        }

        // 6. Visibility, weekly, and last of everything.
        //
        // Last because it is the most expensive item here per run and the least
        // urgent: a sweep is sixty paid calls to third-party models, measured at
        // roughly a dollar, and nothing downstream waits on the result. A
        // project that needs an article written today should get the article.
        //
        // Unlike feedback it does not wait for something to be published.
        // Being absent from every assistant answer is most worth knowing
        // *before* a year of writing rather than after.
        if (! $this->ranWithin($project, 'visibility', 24 * 7)) {
            return [['pipeline' => 'visibility', 'why' => 'checking what the assistants say about the brand']];
        }

        return [];
    }

    /**
     * One locale per unit per tick, and the project's own language first.
     *
     * The three locales of a unit used to start in the same second — the log
     * for 2026-08-08 has three `generation` runs at 08:55:17 — and three runs
     * that begin together can share nothing. Each drew its own hero and its own
     * three section pictures: twelve images for one topic where four would do,
     * because {@see HeroImage} can only reuse what already exists
     * when it looks. Sequencing them is what turns that reuse from an
     * unreachable branch into the normal path.
     *
     * The default locale leads because it is the one the operator reads, and
     * the pictures the whole set will carry are chosen by whichever runs first.
     * Everything after it borrows and pays nothing.
     *
     * Only *within* a unit. Different topics still start together — they have
     * nothing to share and serialising them would cost a project its throughput
     * for no saving. The cap is {@see MAX_STARTS} units, which is what the
     * ceiling was always meant to count: five topics, not five rows that might
     * be one topic in five languages.
     *
     * The rest of the group is not lost, only deferred: it is still `idea`, so
     * the next tick selects it and finds a sibling with pictures to lend. The
     * lead time is a day and the tick is hourly, so a unit has around
     * twenty-four chances to get all of its locales written before its date.
     *
     * @param  Collection<int, ContentItem>  $units
     * @return list<ContentItem>
     */
    private function leadLocales(Collection $units, Project $project): array
    {
        return array_values($units
            ->groupBy('locale_group_id')
            ->take(self::MAX_STARTS)
            ->map(function (Collection $group) use ($project): ContentItem {
                /** @var ContentItem $lead */
                $lead = $group->firstWhere('locale', $project->default_locale) ?? $group->first();

                return $lead;
            })
            ->all());
    }

    /**
     * Approve what the project said may go without a human.
     *
     * `autopublish` was set by the onboarding wizard and read by nobody: only
     * the *channel* flag was honoured, and only after somebody clicked approve.
     * An operator who asked not to be asked was asked anyway, and their drafts
     * sat in the queue forever.
     *
     * Not a pipeline, so it does not go through the runner: it is a state
     * change on rows this project already owns.
     */
    private function approveWhatMayGo(Project $project): int
    {
        // YMYL never auto-approves whatever the flag says — the wizard refuses
        // to set it, and a row edited by hand should not get past this either.
        if (! $project->autopublish || $project->is_ymyl) {
            return 0;
        }

        // Articles only, and `roots()` is what makes that true — a social draft
        // has no parent since §3 and would otherwise be approved here by a
        // project-wide flag that knows nothing about
        // {@see \App\Enums\SocialBand::canEverAutopublish()}. The Conversation
        // and Reaction bands are marked "никогда" in §5, and a post released by
        // an article's rulebook is §10's "аккаунт под автоматикой" happening by
        // omission. Social autopublish is decided by the governor in 12.5.
        $drafts = ContentItem::query()
            ->roots()
            ->inState(ContentItemState::Draft)
            ->whereNotNull('body_markdown')
            ->limit(self::MAX_STARTS)
            ->get();

        $approved = 0;

        foreach ($drafts as $draft) {
            $scored = $this->score->for($draft->loadMissing('assets'));

            if (! $scored['publishable']) {
                // Publishing without review is a privilege, not a bypass. An
                // article that fails a check the style guide calls
                // non-negotiable waits for a human whatever the setting says —
                // otherwise the verdict is a number nobody acts on.
                Log::info('An article was held back from auto-approval', [
                    'unit' => $draft->slug,
                    'blocking' => $scored['blocking'],
                ]);

                continue;
            }

            $draft->approve();
            $approved++;
        }

        return $approved;
    }

    /**
     * Whether this project's *article* contour is still working.
     *
     * Scoped to {@see CONTOUR} rather than to every pipeline, and that scope is
     * the whole point. The reason to wait at all is that these six feed each
     * other — planning a month while research is still filling the pool plans
     * it from half a pool — and a pipeline that feeds none of them is not a
     * reason to wait for anything.
     *
     * The rule is "each contour blocks only itself", not "ignore listening
     * specifically". Both would fix the stall in front of us; only the first
     * one stays fixed. §4.1 gives the listening contour its own hourly schedule
     * entry and {@see SocialListenCommand::isListening()} already scopes its
     * own guard to `social_listen` for the mirror-image reason, and §4.2 and
     * §4.3 add two more contours behind it — engage, which is event-driven and
     * measured in minutes, and publish. Naming one exception would have to be
     * amended once per contour, and the amendment nobody makes is the one that
     * halts research, planning, drafting and publishing for a whole project.
     *
     * What this cost before it was scoped: 12.3 put `social:listen` on the same
     * cron minute as `engine:tick`, both in the background, so every hour the
     * tick had a coin-flip chance of finding a listening run in flight and
     * doing nothing at all. A listening run wedged at `Pending` — a worker
     * down, a dispatch that failed — stopped the project permanently, and the
     * only symptom was one line an hour saying everything was fine.
     *
     * `Pending` counts as busy alongside `Running`: a run is `Pending` from the
     * moment the runner writes the row, and a tick that started a second copy
     * of the same work in that window is the double-start this guards.
     *
     * What it does *not* count is a run that stopped moving hours ago. Waiting
     * is only sane while there is something to wait for, and this guard has no
     * way to make a wedged run finish — so without a bound it converts one lost
     * queue message into a project that never writes again. A `visibility` run
     * wedged on 2026-08-07 did exactly that: two days, no article, and one line
     * an hour saying the project was still working.
     *
     * {@see PipelineRun::scopeInFlight()} carries the bound, and it is
     * deliberately far longer than the threshold `pipeline:reap` works to. The
     * reaper gets a stalled run many times over before this stops waiting for
     * it, which is what keeps the failure mode one-sided: the tick never starts
     * a second copy of work that was one dispatch away from finishing.
     */
    private function isBusy(Project $project): bool
    {
        return PipelineRun::query()
            ->whereIn('pipeline', self::CONTOUR)
            ->inFlight()
            ->exists();
    }

    private function ranWithin(Project $project, string $pipeline, int $hours): bool
    {
        return PipelineRun::query()
            ->where('pipeline', $pipeline)
            ->where('created_at', '>=', Carbon::now()->subHours($hours))
            ->exists();
    }

    /**
     * @return Collection<int, Project>
     */
    private function projects(): Collection
    {
        $query = Project::query()->where('status', ProjectStatus::Active);

        $handle = $this->option('project');

        if (is_string($handle) && $handle !== '') {
            $query->where(fn ($q) => $q->where('slug', $handle)->orWhere('id', $handle));
        }

        return $query->get();
    }
}
