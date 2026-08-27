<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\InteractionState;
use App\Enums\PostKind;
use App\Feedback\ProjectStateTrend;
use App\Models\AssistantThread;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Social\ActivationChecklist;
use App\Social\RefusalLedger;
use App\Support\Engine\WorkInFlight;
use App\Support\Health\StackHealth;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\VisibilityReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screen, singular.
 *
 * This product used to open on three of them — Home, Today and Dashboard — and
 * every one of them was a landing screen. They were not three questions, which
 * is how the code defended them; they were one question asked at three levels
 * of anxiety, and the giveaway was that all three counted the same drafts with
 * different queries and different words. Home said "38 social drafts", the
 * dashboard said "52 waiting for you", and neither said which half of the
 * product it meant. §7 asks for **one** summary and five minutes; three
 * summaries is the violation, whichever one of them is best.
 *
 * So the order here is act, owe, measure, account:
 *
 * 1. **The composer.** The only thing on the screen that starts something.
 * 2. **What needs you** — across both halves, because an operator's morning
 *    does not care that articles and posts are different subsystems.
 * 3. **The figures**, and only the ones that change a decision.
 * 4. **The two halves**, each in one line, because this engine does two jobs and
 *    no screen used to say so out loud.
 * 5. **What the engine did not do**, which §7 makes mandatory and which is
 *    therefore the one band that is never deferred.
 *
 * **Why the halves are a band and not a second screen.** The articles half has
 * no operator verb at all — nothing here is typed, and a month of articles is
 * chosen from keywords by a planning run that fires once at onboarding and is on
 * no schedule afterwards. That is exactly why it needs a line: a half of the
 * product that only ever acts on its own is the half most able to stop without
 * anybody noticing, and on this project it had — the last planning run was
 * seventeen days before the one that found it.
 */
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
                'name' => $project->name,
                'site_name' => (string) ($project->site_analysis['name'] ?? $project->name),
            ],
            'hasProjects' => true,
            'checklist' => ActivationChecklist::for($project, Carbon::now()->startOfMonth()),
            // The kinds an operator may write by hand, each with the channels
            // it goes to — because the kind decides the channels here exactly
            // as it does in a proposal, and the chip has to say so before
            // somebody picks one expecting all three.
            'kinds' => array_map(
                static fn (PostKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'channels' => array_map(
                        static fn ($channel): string => $channel->value,
                        $kind->channels(),
                    ),
                ],
                PostKind::cases(),
            ),

            // The conversations, newest first — the handful worth offering a
            // route back into. The box on this screen starts a new one; the
            // ones that already exist live at their own addresses.
            'chats' => $this->chats(),

            // §7's mandatory line, and therefore not deferred: a line that
            // arrives on a second round trip renders as silence for as long as
            // anybody actually looks at the screen, which is the one thing the
            // paragraph forbids.
            'refusals' => $this->refusals->for($project, $now),

            // Not deferred, for two reasons that are really one. A project in
            // its first hour has nothing else on this screen — deferring it
            // means the launch renders as an empty page and then fills in — and
            // `WorkInFlight` is also what settles a launch whose chain died, so
            // behind a deferred prop that repair only runs for somebody who
            // stays long enough for the second request.
            'work' => $this->work->for($project),

            'needs' => Inertia::defer(fn (): array => $this->needs($now)),
            'figures' => Inertia::defer(fn (): array => $this->figures($project, $trend, $now)),
            'halves' => Inertia::defer(fn (): array => $this->halves($now)),
            'health' => Inertia::defer(fn (): array => $health->check()),
        ]);
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

    /**
     * Everything with a person's name on it, in one count.
     *
     * The merge is the point. These five numbers used to live on three screens
     * under three headings, and the two that overlapped disagreed — one scoped
     * to `social()` and the other to `roots()`, neither saying so. Counting them
     * once, here, is what lets the band answer the only question anybody opens
     * this product to ask: can I close the tab.
     *
     * @return array<string, mixed>
     */
    private function needs(CarbonImmutable $now): array
    {
        $open = Interaction::query()->open()->get();

        $socialDrafts = ContentItem::query()->social()
            ->inState(ContentItemState::Draft)->count();
        $articleDrafts = ContentItem::query()->roots()
            ->inState(ContentItemState::Draft)->count();
        $articleApprovals = ContentItem::query()->roots()
            ->inState(ContentItemState::Approved)->count();
        $replyDrafts = $open
            ->where('state', InteractionState::Drafted)
            ->count();
        $dead = WebhookDelivery::query()
            ->where('status', DeliveryStatus::DeadLetter->value)
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
            'social' => [
                'planned' => (clone $social)->inState(ContentItemState::Idea)->count(),
                'drafted' => (clone $social)->inState(ContentItemState::Draft)->count(),
                'approved' => (clone $social)->inState(ContentItemState::Approved)->count(),
                'published' => (clone $social)->inState(ContentItemState::Published)->count(),
                // Whether this month has been proposed at all. `firstOrCreate`
                // makes a bare row the moment anybody types an idea, so the
                // version is what says a planner ran — not the row's existence.
                'month_proposed' => $plannedMonth !== null
                    && $plannedMonth->assistant_version > 0,
            ],
        ];
    }
}
