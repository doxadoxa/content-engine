<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Enums\InteractionSkipReason;
use App\Enums\InteractionState;
use App\Feedback\ProjectStateTrend;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\SocialPlan;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §7 — the operator's day, on one screen, in five minutes.
 *
 * > День проекта — одна сводка и пять минут: что произошло; что ждёт
 * > подтверждения; что изменилось в состоянии проекта; чего движок делать не
 * > стал и почему.
 *
 * The four sections below are those four lines, in that order, and the order is
 * not cosmetic: it runs from what the world did to us, through what we owe it,
 * to what it all added up to, and ends with the engine accounting for its own
 * silence.
 *
 * # This screen reimplements nothing
 *
 * Every number here is read from the object that already owns it. The
 * conversations come through {@see Interaction::scopeOpen()} — the reply
 * queue's only query — and their waits are computed with the same expression
 * {@see InteractionController::row()} uses, against the same clock, because two
 * screens disagreeing about how long somebody has been waiting is worse than
 * one screen. The refusals come off {@see SocialPlan}, where the planner and
 * the drafting step wrote them. The trends come from {@see ProjectStateTrend}.
 * Nothing here recounts, re-derives or re-renders the reply queue: the drafts
 * section links into it, because it is already one tap and a second copy would
 * be a second thing to keep in step.
 *
 * # Why the last section is not deferred
 *
 * "Чего движок делать не стал и почему" is the line §7 calls mandatory, and a
 * mandatory line that arrives on a second round trip is a screen that renders
 * as silence for as long as anybody actually looks at it. `changed` is deferred
 * instead — it reads a month of `project_states` and does arithmetic over it,
 * and nobody opens this screen for the sparkline first.
 *
 * # Who may open it
 *
 * Any member of the project, matching `content.approve` and `engage.*` rather
 * than `metering.index`. §7 is the operator's routine, and a summary only the
 * account holder may read is a summary nobody reads at 8am.
 */
class DailySummaryController extends Controller
{
    /**
     * The first hour of §4.2, in seconds, and the quarter of it that is still
     * comfortable.
     *
     * The same two thresholds `engage/index.tsx` colours its rows with. They
     * are here so that "past the first hour" means one thing across both
     * screens — the algorithm weighs the speed of the first hour, and a digest
     * calling something urgent that the queue paints green teaches an operator
     * to trust neither.
     */
    private const int FIRST_HOUR = 3_600;

    private const int STILL_COMFORTABLE = 900;

    /** How many rows each section shows before it becomes a link. */
    private const int PREVIEW = 5;

    public function __construct(private readonly CurrentProject $current) {}

    public function __invoke(Request $request, ProjectStateTrend $trend): Response
    {
        $project = $this->current->get();

        if (! $project instanceof Project) {
            return Inertia::render('today/index', ['project' => null]);
        }

        $now = CarbonImmutable::now();
        $today = $now->setTimezone($project->timezone)->startOfDay();

        return Inertia::render('today/index', [
            'project' => [
                'name' => $project->name,
                'timezone' => $project->timezone,
                'today' => $today->toDateString(),
            ],

            'happened' => $this->happened($today, $now),
            'awaiting' => $this->awaiting($project, $today, $now),

            // Mandatory, and therefore first-class. See the class docblock.
            'not_done' => $this->notDone($project, $now),

            // Deferred: a fortnight of rows against the fortnight before it,
            // three times over, for a card nobody lands here to read first.
            'changed' => Inertia::defer(fn (): array => $trend->for($project, $now)),
        ]);
    }

    /**
     * Section one — what happened, sorted by urgency.
     *
     * Urgency is how long something has waited and nothing else. §4.2 is judged
     * on the first hour, so the conversation that has been waiting longest is
     * the most urgent one by definition, which is exactly the order
     * {@see Interaction::scopeOpen()} already returns. The buckets are a
     * reading of that same order, not a second sort.
     *
     * @return array<string, mixed>
     */
    private function happened(CarbonImmutable $today, CarbonImmutable $now): array
    {
        $open = Interaction::query()->open()->get();

        $waits = $open->map(fn (Interaction $conversation): int => $this->waited($conversation, $now));

        return [
            'total' => $open->count(),
            // Both halves of the day so far. "Six waiting" means one thing on a
            // morning when nothing has come in and another on an afternoon when
            // forty did, and the second number is the one that says which.
            'arrived_today' => Interaction::query()
                ->where('received_at', '>=', $today->utc())
                ->count(),
            'answered_today' => Interaction::query()
                ->whereNotNull('answered_at')
                ->where('answered_at', '>=', $today->utc())
                ->count(),
            'longest_wait_seconds' => $waits->max(),
            'buckets' => [
                [
                    'key' => 'overdue',
                    'label' => 'Past the first hour',
                    'count' => $waits->filter(fn (int $s): bool => $s >= self::FIRST_HOUR)->count(),
                ],
                [
                    'key' => 'closing',
                    'label' => 'Still inside the hour',
                    'count' => $waits->filter(
                        fn (int $s): bool => $s >= self::STILL_COMFORTABLE && $s < self::FIRST_HOUR
                    )->count(),
                ],
                [
                    'key' => 'fresh',
                    'label' => 'Just arrived',
                    'count' => $waits->filter(fn (int $s): bool => $s < self::STILL_COMFORTABLE)->count(),
                ],
            ],
            'conversations' => $open
                ->take(self::PREVIEW)
                ->map(fn (Interaction $conversation): array => [
                    'id' => (string) $conversation->getKey(),
                    'author' => $conversation->author,
                    'handle' => $conversation->author_handle,
                    'excerpt' => Str::limit($conversation->text, 140),
                    'permalink' => $conversation->permalink,
                    'received_at' => $conversation->received_at->toIso8601String(),
                    // The same expression the reply queue puts on its rows, so
                    // the two screens cannot disagree about a wait.
                    'waited_seconds' => $this->waited($conversation, $now),
                    'state' => $conversation->state->value,
                    'state_label' => $conversation->state->label(),
                    'has_draft' => $conversation->draft_reply !== null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Section two — what is waiting for a human.
     *
     * Two things and no more: the replies the engine has written and cannot
     * send (§4.2 forbids that permanently), and the day's post. Both are links
     * rather than forms. The reply queue is already one tap and re-rendering it
     * here would be a second implementation of the one screen the latency
     * target is measured on.
     *
     * @return array<string, mixed>
     */
    private function awaiting(Project $project, CarbonImmutable $today, CarbonImmutable $now): array
    {
        $drafted = Interaction::query()
            ->open()
            ->where('state', InteractionState::Drafted)
            ->get();

        return [
            'reply_drafts' => [
                'count' => $drafted->count(),
                'rows' => $drafted
                    ->take(self::PREVIEW)
                    ->map(fn (Interaction $conversation): array => [
                        'id' => (string) $conversation->getKey(),
                        'author' => $conversation->author,
                        'handle' => $conversation->author_handle,
                        'draft_excerpt' => Str::limit((string) $conversation->draft_reply, 160),
                        'waited_seconds' => $this->waited($conversation, $now),
                    ])
                    ->values()
                    ->all(),
            ],
            'post_of_the_day' => $this->postOfTheDay($project, $today),
        ];
    }

    /**
     * The slot standing on today's calendar, in whatever state it is in.
     *
     * Including the empty one. A slot the drafting run left in `idea` with no
     * body is the most interesting thing this section can contain — it is §4.3's
     * valid result and the reason section four exists — so it is reported as an
     * empty slot rather than omitted as "no post today". The two are opposite
     * facts: nothing was planned, against something was planned and came to
     * nothing.
     *
     * @return array<string, mixed>|null
     */
    private function postOfTheDay(Project $project, CarbonImmutable $today): ?array
    {
        // Bound in UTC. Laravel drops the offset when it formats a bound date,
        // so a local-time bind is read back in the session timezone and the day
        // silently moves — the same trap {@see \App\Social\Governor} documents.
        $unit = ContentItem::query()
            ->social()
            ->whereBetween('slot_at', [$today->utc(), $today->addDay()->utc()])
            ->orderBy('slot_at')
            ->first();

        if (! $unit instanceof ContentItem) {
            return null;
        }

        $empty = ! $unit->state->isLive()
            && ($unit->body_markdown === null || trim($unit->body_markdown) === '');

        return [
            'id' => (string) $unit->getKey(),
            'title' => $unit->title,
            'state' => $unit->state->value,
            'state_label' => $unit->state->label(),
            'band' => $unit->social_band?->value,
            'band_label' => $unit->social_band?->label(),
            'slot_at' => $unit->slot_at?->toIso8601String(),
            'expires_at' => $unit->expires_at?->toIso8601String(),
            'expired' => $unit->hasExpired(),
            'published' => $unit->state->isLive(),
            'empty' => $empty,
            'url' => route('content.show', $unit),
        ];
    }

    /**
     * Section four — what the engine did not do, and why. Mandatory (§7).
     *
     * > Последняя строка обязательна. Молчащий автомат неотличим от сломанного,
     * > и единственная вещь, которая делает «сегодня не публикуем» приемлемой,
     * > — объяснение рядом.
     *
     * Five kinds of silence are gathered, because they are five ways the same
     * question gets answered and an operator should not have to visit five
     * screens to hear all of them:
     *
     * - slots the planner never placed and slots the drafting run left empty,
     *   both written to the week's row under `empty_slot`;
     * - reactive drafts `social:kill-expired` killed rather than published late;
     * - the governor's throttle, when a weak trailing reply rate cut the week;
     * - the floor's alert, when presence is below §4.3's minimum;
     * - conversations the operator skipped, with the reason they chose.
     *
     * And when none of them fired, the section says *that* — in a sentence,
     * where the reasons would have been. Rendering nothing is the one thing §7
     * forbids: an empty panel is exactly the silence that cannot be told apart
     * from a broken scheduler.
     *
     * @return array<string, mixed>
     */
    private function notDone(Project $project, CarbonImmutable $now): array
    {
        $weekStart = SocialPlan::weekStart($project, $now);
        $plan = SocialPlan::forWeek($project, $now);

        /** @var list<array<string, mixed>> $entries */
        $entries = [];

        foreach ($plan === null ? [] : $plan->reasons as $reason) {
            $entries[] = [
                'code' => $reason['code'],
                'label' => $this->refusalLabel($reason['code']),
                'detail' => $reason['detail'],
                'at' => $reason['at'],
            ];
        }

        $throttle = null;

        if ($plan !== null && $plan->throttled) {
            $rate = $plan->reply_rate;

            $throttle = [
                'reply_rate' => $rate,
                'ceiling' => $plan->ceiling,
                'selection_floor' => $plan->selection_floor,
                'detail' => 'The trailing reply rate '
                    .($rate === null ? 'fell below the threshold' : 'is '.number_format($rate * 100, 2).'%')
                    .", so §4.3 cut this week's ceiling to {$plan->ceiling} posts and raised the selection bar "
                    ."to {$plan->selection_floor}. Fewer posts and a pickier engine, both on purpose: a weak "
                    .'week costs the next one its reach.',
            ];

            $entries[] = [
                'code' => 'throttled',
                'label' => $this->refusalLabel('throttled'),
                'detail' => $throttle['detail'],
                'at' => null,
            ];
        }

        if ($plan !== null && $plan->alert !== null) {
            $entries[] = [
                'code' => 'floor',
                'label' => $this->refusalLabel('floor'),
                'detail' => $plan->alert,
                'at' => null,
            ];
        }

        foreach ($this->skipped($weekStart) as $entry) {
            $entries[] = $entry;
        }

        // A project that posts and has no record of a decision for this week is
        // the silence §7 is actually about: the planner did not run, and
        // without this line the screen would look like a week in which nothing
        // needed doing. Gated on the project having a presence at all, because
        // on a blog-only project the absence of a social plan is not news.
        if ($plan === null && $this->hasPresence()) {
            $entries[] = [
                'code' => 'not_planned',
                'label' => $this->refusalLabel('not_planned'),
                'detail' => 'No planning run has recorded a decision for the week of '
                    .$weekStart->toDateString().'. That is not the same as deciding to post nothing — '
                    .'nobody decided anything, which usually means the weekly planner did not run.',
                'at' => null,
            ];
        }

        $nothing = $entries === [];

        return [
            'week_start' => $weekStart->toDateString(),
            'entries' => $entries,
            'throttle' => $throttle,
            'alert' => $plan?->alert,
            'planned' => $plan?->planned,
            'ceiling' => $plan?->ceiling,
            'floor' => $plan?->floor,
            'nothing_to_report' => $nothing,
            // Always a sentence, never an empty panel. §7's last line is
            // mandatory, and "the engine refused nothing" is itself the
            // explanation that makes today's silence readable.
            'summary' => $nothing
                ? 'Nothing was refused this week. No slot was left empty, no draft was killed, the governor '
                    .'did not throttle, and no conversation was skipped — the engine has nothing it declined '
                    .'to do.'
                : count($entries).' '.(count($entries) === 1 ? 'thing' : 'things')
                    .' the engine did not do this week, each with the reason it did not.',
        ];
    }

    /**
     * Conversations a person decided to leave alone, this week.
     *
     * On the same list as the engine's refusals because they are the same kind
     * of fact — a thread that got no answer — and §7's screen is one screen.
     * The reason is a code from a closed set, so the label is the operator's
     * own words rather than a sentence reconstructed here.
     *
     * @return list<array<string, mixed>>
     */
    private function skipped(CarbonImmutable $weekStart): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = Interaction::query()
            ->where('state', InteractionState::Ignored)
            ->whereNotNull('ignored_reason')
            ->where('updated_at', '>=', $weekStart->utc())
            ->latest('updated_at')
            ->limit(self::PREVIEW)
            ->get()
            ->map(function (Interaction $conversation): array {
                $reason = InteractionSkipReason::tryFrom((string) $conversation->ignored_reason);
                $who = $conversation->author_handle ?? $conversation->author;

                return [
                    'code' => 'skipped',
                    'label' => $this->refusalLabel('skipped'),
                    'detail' => "{$who} was left unanswered: "
                        .($reason?->label() ?? (string) $conversation->ignored_reason).'.',
                    'at' => $conversation->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return $rows;
    }

    /**
     * Whether this project has a social presence to be silent about.
     *
     * Cheap on purpose — two `exists()` against indexed columns — because it
     * only ever decides whether one extra line appears.
     */
    private function hasPresence(): bool
    {
        return Channel::query()->where('type', ChannelType::Threads)->exists()
            || ContentItem::query()->social()->exists();
    }

    /** The heading a refusal appears under. One per code the engine writes. */
    private function refusalLabel(string $code): string
    {
        return match ($code) {
            'empty_slot' => 'Slot left empty',
            'killed' => 'Draft killed rather than published late',
            'throttled' => 'The week was throttled',
            'floor' => 'Below the floor',
            'skipped' => 'Conversation skipped',
            'not_planned' => 'The week was never planned',
            default => 'Not done',
        };
    }

    /**
     * How long this person has been waiting, on the server's clock.
     *
     * The same expression {@see InteractionController::row()} sends to the
     * reply queue, against the same clock. The number §4.2 is judged on has one
     * definition, and a digest that derived it on the client — or from
     * `received_at` alone — would put two answers to one question on two
     * screens an operator reads a minute apart.
     *
     * The instant is taken once for the whole request rather than per row, so
     * the buckets and the rows they count agree with each other.
     */
    private function waited(Interaction $conversation, CarbonImmutable $now): int
    {
        return (int) $conversation->received_at->diffInSeconds($now);
    }
}
