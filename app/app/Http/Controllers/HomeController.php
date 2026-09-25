<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Billing\PlanCatalog;
use App\Billing\PlanSelection;
use App\Billing\Subscriptions;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Feedback\ManagerResults;
use App\Models\ArticleSchedule;
use App\Models\AssistantThread;
use App\Models\ContentItem;
use App\Models\PageOpportunity;
use App\Models\PageOutcomeReview;
use App\Models\PageProposal;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Onboarding\WebsiteChecklist;
use App\Publishing\Articles\ArticleSchedules;
use App\Support\Content\ManagerContent;
use App\Support\Engine\WorkInFlight;
use App\Support\Health\StackHealth;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use App\Visibility\VisibilityReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The manager's daily summary: content in progress, upcoming publication, and observed results. */
class HomeController extends Controller
{
    public function __construct(
        private readonly WorkInFlight $work,
    ) {}

    public function __invoke(
        Request $request,
        CurrentProject $current,
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
                // the one part of it worth keeping verbatim. Archived ones are
                // nothing to pick.
                'hasProjects' => ProjectManager::live($user)->exists(),
                'checklist' => [],
            ]);
        }

        return Inertia::render('home/index', [
            'project' => [
                'id' => (string) $project->getKey(),
                'name' => $project->name,
                'site_name' => (string) ($project->site_analysis['name'] ?? $project->name),
            ],
            'hasProjects' => true,
            'checklist' => WebsiteChecklist::for($project),

            // The conversations, newest first — the handful worth offering a
            // route back into. The box on this screen starts a new one; the
            // ones that already exist live at their own addresses.
            'chats' => $this->chats(),

            // Not deferred, for two reasons that are really one. A project in
            // its first hour has nothing else on this screen — deferring it
            // means the launch renders as an empty page and then fills in — and
            // `WorkInFlight` is also what settles a launch whose chain died, so
            // behind a deferred prop that repair only runs for somebody who
            // stays long enough for the second request.
            // Not deferred, for the same reason `work` is not: during a
            // preview this panel *is* the screen, and a dashboard that paints
            // its empty grid first and fills the point in afterwards is the
            // version somebody closes.
            'preview' => $this->preview($request, $project),
            'work' => $this->work->for($project),
            'article_workflow' => ManagerContent::workflow($project),
            'pageWork' => Inertia::defer(fn (): array => $this->pageWork()),
            'manager' => Inertia::defer(fn (): array => $this->manager($project)),
            // The dashboard's primary purpose: show the saved results on first paint.
            'results' => fn (): array => app(ManagerResults::class)->for($project),

            'needs' => Inertia::defer(fn (): array => $this->needs()),
            'figures' => Inertia::defer(fn (): array => $this->figures()),
            'halves' => Inertia::defer(fn (): array => $this->halves()),
            'health' => Inertia::defer(fn (): array => $health->check()),
        ]);
    }

    /**
     * The card-free sample, while it is the thing on this screen.
     *
     * Null for everybody else, which is almost everybody: a project is only
     * ever in this state between finishing the wizard and answering the card
     * question. See {@see Subscriptions::startPreview()}.
     *
     * @return array<string, mixed>|null
     */
    private function preview(Request $request, Project $project): ?array
    {
        $entitlement = app(Entitlements::class)->for($project);

        if (! $entitlement->isPreview()) {
            return null;
        }

        // What the sample is of: the plan chosen in the wizard, which is what
        // the "start my trial" button has to buy. The preview's own plan is
        // the allowance it ran on and is not for sale.
        $plan = app(PlanSelection::class)->selected($request, $project);

        $draft = ContentItem::query()
            ->whereNotIn('state', [ContentItemState::Idea])
            ->where('locale', $project->default_locale)
            ->latest()
            ->first();

        return [
            'finished' => $entitlement->previewFinished,
            // Every topic the month's plan holds, drafted or not. This is the
            // larger half of what a sample is worth: one article says whether
            // the writing is good, a calendar says whether we understood the
            // business.
            'topics' => ContentItem::query()->whereNotNull('content_plan_id')->count(),
            'draft' => $draft === null ? null : [
                'id' => (string) $draft->getKey(),
                'title' => $draft->title,
                // Counted off the prose rather than stored, because nothing
                // stores it and the number is the argument: "1,400 words
                // written from your site" is what makes the link worth
                // pressing.
                'words' => str_word_count(strip_tags((string) $draft->body_markdown)),
            ],
            'plan' => [
                'key' => $plan->key,
                'name' => $plan->name,
                'price_cents' => $plan->priceCents,
                'currency' => $plan->currency,
                'articles' => $plan->limit('articles'),
            ],
            'trial_days' => app(PlanCatalog::class)->trialDays(),
        ];
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
    private function needs(): array
    {
        $articleDrafts = ContentItem::query()
            ->inState(ContentItemState::Draft)->count();
        $articleApprovals = ContentItem::query()
            ->inState(ContentItemState::Approved)->count();
        $dead = WebhookDelivery::query()
            ->where('status', DeliveryStatus::DeadLetter->value)
            ->whereNotNull('content_item_id')
            ->count();

        return [
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
            'total' => $articleDrafts + $articleApprovals,
        ];
    }

    /**
     * The figures that change what an operator does next.
     *
     * Deliberately short. Published counts, targeted search volume, citation
     * coverage and impressions are all facts about the past that change no
     * decision, and each of them already sits on the screen that owns it.
     *
     * @return array<string, mixed>
     */
    private function figures(): array
    {
        $report = VisibilityReport::latest();

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
        ];
    }

    /**
     * The engine's article half, in one line.
     *
     * What is planned, what is drafted and approved, and what is out — because
     * a pipeline that has stopped planning looks exactly like a quiet week
     * until the counts are side by side.
     *
     * @return array<string, mixed>
     */
    private function halves(): array
    {
        $articles = ContentItem::query();

        return [
            'articles' => [
                'planned' => (clone $articles)->inState(ContentItemState::Idea)->count(),
                'drafted' => (clone $articles)->inState(ContentItemState::Draft)->count(),
                'approved' => (clone $articles)->inState(ContentItemState::Approved)->count(),
                'published' => (clone $articles)->inState(ContentItemState::Published)->count(),
            ],
        ];
    }
}
