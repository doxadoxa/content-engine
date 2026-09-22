<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\InteractionState;
use App\Enums\PostKind;
use App\Feedback\ManagerResults;
use App\Feedback\ProjectStateTrend;
use App\Models\ArticleSchedule;
use App\Models\AssistantThread;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Interaction;
use App\Models\PageOpportunity;
use App\Models\PageOutcomeReview;
use App\Models\PageProposal;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Onboarding\WebsiteChecklist;
use App\Publishing\Articles\ArticleSchedules;
use App\Social\ActivationChecklist;
use App\Social\RefusalLedger;
use App\Support\Content\ManagerContent;
use App\Support\Engine\WorkInFlight;
use App\Support\Health\StackHealth;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\VisibilityReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** The manager's daily summary: content in progress, upcoming publication, and observed results. */
class HomeController extends Controller
{
    public function __construct(
        private readonly WorkInFlight $work,
        private readonly RefusalLedger $refusals,
    ) {}

    public function __invoke(
        Request $request,
        CurrentProject $current,
        ProjectStateTrend $trend,
        StackHealth $health,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $project = $current->get();

        if ($project === null) {
            return Inertia::render('home/index', [
                'project' => null,
                // "Pick one" and "make your first" are different screens, and
                // this distinction came off the dashboard's own empty state —
                // the one part of it worth keeping verbatim.
                'hasProjects' => $user->projects()->exists(),
                'checklist' => [],
                'kinds' => [],
            ]);
        }

        $now = CarbonImmutable::now($project->timezone);

        return Inertia::render('home/index', [
            'project' => [
                'id' => (string) $project->getKey(),
                'name' => $project->name,
                'site_name' => (string) ($project->site_analysis['name'] ?? $project->name),
            ],
            'hasProjects' => true,
            'checklist' => config('social.enabled')
                ? ActivationChecklist::for($project, Carbon::now()->startOfMonth())
                : WebsiteChecklist::for($project),
            // The kinds an operator may write by hand, each with the channels
            // it goes to — because the kind decides the channels here exactly
            // as it does in a proposal, and the chip has to say so before
            // somebody picks one expecting all three.
            'kinds' => config('social.enabled') ? array_map(
                static fn (PostKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'channels' => array_map(
                        static fn ($channel): string => $channel->value,
                        $kind->channels(),
                    ),
                ],
                PostKind::cases(),
            ) : [],

            // The conversations, newest first — the handful worth offering a
            // route back into. The box on this screen starts a new one; the
            // ones that already exist live at their own addresses.
            'chats' => $this->chats(),

            // §7's mandatory line, and therefore not deferred: a line that
            // arrives on a second round trip renders as silence for as long as
            // anybody actually looks at the screen, which is the one thing the
            // paragraph forbids.
            'refusals' => config('social.enabled') ? $this->refusals->for($project, $now) : null,

            // Not deferred, for two reasons that are really one. A project in
            // its first hour has nothing else on this screen — deferring it
            // means the launch renders as an empty page and then fills in — and
            // `WorkInFlight` is also what settles a launch whose chain died, so
            // behind a deferred prop that repair only runs for somebody who
            // stays long enough for the second request.
            'work' => $this->work->for($project),
            'article_workflow' => ManagerContent::workflow($project),
            'pageWork' => Inertia::defer(fn (): array => $this->pageWork()),
            'manager' => Inertia::defer(fn (): array => $this->manager($project)),
            // The dashboard's primary purpose: show the saved results on first paint.
            'results' => fn (): array => app(ManagerResults::class)->for($project),

            'needs' => Inertia::defer(fn (): array => $this->needs($now)),
            'figures' => Inertia::defer(fn (): array => $this->figures($project, $trend, $now)),
            'halves' => Inertia::defer(fn (): array => $this->halves($now)),
            'health' => Inertia::defer(fn (): array => $health->check()),
        ]);
    }

    /** @return array<string, mixed> */
    private function manager(Project $project): array
    {
        $schedules = app(ArticleSchedules::class);
        $row = function (ContentItem $item) use ($schedules): array {
            $publication = $schedules->props($item);

            return ['id' => $item->id, 'title' => $item->title, 'status' => $publication['status'],
                'publish_at' => $item->state->isLive() ? $item->published_at?->toIso8601String() : ($publication['schedule']['publish_at'] ?? null),
                'reason' => $publication['schedule']['blocked_reason'] ?? null];
        };
        $upcoming = ManagerContent::query()->whereNotIn('state', ['published', 'refreshing'])
            ->whereHas('articleSchedule', fn ($schedule) => $schedule->where('status', 'active')->where('publish_at', '>=', now()))
            ->orderBy(ArticleSchedule::query()->select('publish_at')->whereColumn('content_item_id', 'content_items.id')->limit(1))
            ->with(['articleSchedule.delivery', 'project.channels'])->limit(5)->get();

        return [
            'mode' => $project->autopublish ? 'automatic' : 'review_first', 'timezone' => $project->timezone,
            'workflow' => ManagerContent::workflow($project),
            'has_articles' => ManagerContent::query()->exists(),
            'writing' => ManagerContent::query('writing')->count(), 'needs_review' => ManagerContent::query('review')->count(),
            'scheduled' => ManagerContent::query('scheduled')->count(), 'published' => ManagerContent::query('published')->count(),
            'upcoming' => $upcoming->map($row)->values()->all(),
            'attention' => ManagerContent::query('review')->with(['articleSchedule.delivery', 'project.channels'])->latest()->limit(5)->get()->map($row)->all(),
            'recent' => ManagerContent::query('published')->with(['articleSchedule.delivery', 'project.channels'])->latest('published_at')->limit(4)->get()->map($row)->all(),
        ];
    }

    /**
     * The last few conversations, as a way back into them.
     *
     * Five, not fifty: this is a landing screen and the full list has a page of
     * its own. Enough to recognise the thing you were in the middle of.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chats(): array
    {
        return AssistantThread::query()
            ->recent()
            ->limit(5)
            ->get()
            ->map(static fn (AssistantThread $thread): array => [
                'id' => (string) $thread->getKey(),
                'title' => $thread->title,
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<string,mixed> */
    private function pageWork(): array
    {
        $items = PageProposal::query()->whereHas('page')->with(['page', 'publications'])->where('status', '!=', 'dismissed')->latest()->limit(20)->get()
            ->map(function (PageProposal $proposal): array {
                $publication = $proposal->publications->firstWhere('revision_id', $proposal->current_revision_id);
                $decision = $publication ? PageOutcomeReview::query()->where('publication_id', $publication->id)->latest('id')->first() : null;
                $status = $publication?->verified_at !== null ? ($decision ? 'reviewed' : 'observing') : $proposal->status;
                if ($publication !== null && in_array($publication->status, ['verification_failed', 'recovery_verification_failed', 'review_required'], true)) {
                    $status = 'review_required';
                }

                return ['id' => $proposal->id, 'title' => $proposal->page->title, 'status' => $status, 'decision' => $decision?->decision,
                    'recovered_at' => $publication?->recovered_at?->toIso8601String()];
            })->all();

        return ['opportunities' => PageOpportunity::query()->where('status', 'open')->count(), 'items' => $items];
    }

    /** @return array<string,mixed> */
    private function needs(CarbonImmutable $now): array
    {
        $open = Interaction::query()->open()
            ->when(! config('social.enabled'), fn ($query) => $query->whereRaw('1 = 0'))->get();

        $socialDrafts = config('social.enabled') ? ContentItem::query()->social()
            ->inState(ContentItemState::Draft)->count() : 0;
        $articleDrafts = ContentItem::query()->roots()
            ->inState(ContentItemState::Draft)->count();
        $articleApprovals = ContentItem::query()->roots()
            ->inState(ContentItemState::Approved)->count();
        $replyDrafts = $open
            ->where('state', InteractionState::Drafted)
            ->count();
        $dead = WebhookDelivery::query()
            ->where('status', DeliveryStatus::DeadLetter->value)
            ->when(! config('social.enabled'), fn ($query) => $query->whereIn('content_item_id', ContentItem::query()->roots()->select('id')))
            ->count();

        return [
            'conversations' => $open->count(),
            // The same expression the reply queue sends, against the same
            // clock. The number §4.2 is judged on has one definition, and two
            // screens read a minute apart must not answer it differently.
            'longest_wait_seconds' => $open
                ->map(static fn (Interaction $row): int => (int) $row->received_at->diffInSeconds($now))
                ->max(),
            'reply_drafts' => $replyDrafts,
            'social_drafts' => $socialDrafts,
            'article_drafts' => $articleDrafts,
            'page_reviews' => PageProposal::query()->whereIn('status', ['review_required', 'approved', 'failed'])->whereDoesntHave('publications', fn ($query) => $query->whereNotNull('verified_at')->whereColumn('page_publications.revision_id', 'page_proposals.current_revision_id'))->count(),
            // Approved and not gone out. On the project this screen was built
            // against there were fifty-two of them against zero published, and
            // no screen in the product said so above a whisper.
            'article_approvals' => $articleApprovals,
            'dead_deliveries' => $dead,
            // Things with a person's name on them, and deliberately **not**
            // including the dead deliveries. Those are one incident however
            // many rows it wrote — ninety-two of them counted as ninety-two
            // tasks turned this headline into "184 things need you", which is
            // both true and useless. They get their own line instead, where a
            // failure of the engine belongs.
            'total' => $open->count() + $socialDrafts + $articleDrafts + $articleApprovals,
        ];
    }

    /**
     * The three figures that change what an operator does next.
     *
     * Deliberately short. Published counts, targeted search volume, citation
     * coverage and impressions are all facts about the past that change no
     * decision, and each of them already sits on the screen that owns it.
     *
     * @return array<string, mixed>
     */
    private function figures(Project $project, ProjectStateTrend $trend, CarbonImmutable $now): array
    {
        $report = VisibilityReport::latest();
        $metrics = collect($trend->for($project, $now))->keyBy('key');

        return [
            'visibility' => [
                // Null rather than 0 all the way to the component. "You are in
                // none of the answers" and "nothing has been asked yet" are
                // opposite facts that both render as 0% if the null is coerced
                // away here.
                'score' => $report->score(),
                'last_asked_on' => $report->lastAskedOn?->toDateString(),
                'monitored_prompts' => $report->monitoredPrompts(),
            ],
            'audience' => $metrics->get('brand_demand'),
            'visitors' => $metrics->get('direct_traffic'),
        ];
    }

    /**
     * The two jobs this engine does, one line each.
     *
     * Both halves report the same three things — what is planned, what is out,
     * and when the machine that fills them last ran — because the interesting
     * failure is identical on both sides and neither screen used to show it: a
     * half that has stopped planning looks exactly like a half with a quiet
     * week until you go looking for the last run.
     *
     * @return array<string, mixed>
     */
    private function halves(CarbonImmutable $now): array
    {
        $articles = ContentItem::query()->roots();
        $social = ContentItem::query()->social();

        $month = $now->startOfMonth()->toDateString();
        $plannedMonth = ContentPlan::query()
            ->whereDate('month', $month)
            ->first();

        return [
            'articles' => [
                'planned' => (clone $articles)->inState(ContentItemState::Idea)->count(),
                'drafted' => (clone $articles)->inState(ContentItemState::Draft)->count(),
                'approved' => (clone $articles)->inState(ContentItemState::Approved)->count(),
                'published' => (clone $articles)->inState(ContentItemState::Published)->count(),
            ],
            'social' => config('social.enabled') ? [
                'planned' => (clone $social)->inState(ContentItemState::Idea)->count(),
                'drafted' => (clone $social)->inState(ContentItemState::Draft)->count(),
                'approved' => (clone $social)->inState(ContentItemState::Approved)->count(),
                'published' => (clone $social)->inState(ContentItemState::Published)->count(),
                // Whether this month has been proposed at all. `firstOrCreate`
                // makes a bare row the moment anybody types an idea, so the
                // version is what says a planner ran — not the row's existence.
                'month_proposed' => $plannedMonth !== null
                    && $plannedMonth->assistant_version > 0,
            ] : null,
        ];
    }
}
