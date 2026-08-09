<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\DeliveryStatus;
use App\Enums\SocialBand;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectState;
use App\Models\WebhookDelivery;
use App\Pipelines\Definitions\SocialPlanPipeline;
use App\Publishing\PublishToChannels;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

/**
 * The ceiling and the floor of §4.3, in one place.
 *
 * > | Потолок | ≤5/нед, ≤2/день | trailing reply rate ниже порога → срезает
 * >   частоту и поднимает планку отбора |
 * > | Пол | ≥2/нед + ответы | присутствие не должно умирать: недобор — алерт
 * >   оператору, а не тишина |
 *
 * A service and not a pipeline step, because the two failures it guards happen
 * in different contours. {@see SocialPlanPipeline} asks it weekly, before it
 * places anything; the drafting pipeline and the publisher have to be able to
 * ask it again at the moment of sending, when a week that had room on Monday
 * may not have room on Friday. A step could only answer the first question.
 *
 * **Arithmetic, and nothing but.** §4.3 requires `guard_policy` to be
 * "детерминированный, без вызова модели" and the same discipline is what makes
 * a ceiling a ceiling: an answer that varies between two identical weeks is a
 * suggestion. Everything here is a count, a division and a comparison, so the
 * same project on the same day gets the same verdict from a test, from a
 * command and from a queue worker.
 *
 * **Reads across the tenant scope on purpose.** Every query below names
 * `project_id` explicitly and goes through `acrossProjects()`, so the governor
 * answers for the project it was handed rather than for whichever project
 * happens to be current. A guard that silently returns "nothing published this
 * week" because the tenant was not set is a guard that opens the ceiling.
 */
final class Governor
{
    /**
     * Both halves of the throttle, so a weak week is quieter *and* pickier.
     *
     * Fewer slots alone is the same week with the worst candidate dropped —
     * still publishing whatever the pool happened to contain, just less of it.
     * §4.3 says the rate cuts frequency and raises the bar, and the second half
     * is the one that changes what gets published rather than how much.
     *
     * `$sending` switches the counting from what was *planned* to what will
     * actually be on the account, and is the difference between the two moments
     * this class was written to serve. See {@see taken()}.
     */
    public function verdict(
        Project $project,
        ?CarbonInterface $within = null,
        ?ContentItem $sending = null,
    ): GovernorVerdict {
        $timezone = $project->timezone;
        $at = CarbonImmutable::instance($within ?? now())->setTimezone($timezone);
        $weekStart = $at->startOfWeek();

        [$rate, $measuredDays] = $this->trailingReplyRate($project, $at);

        $throttled = $rate !== null && $rate < $this->float('weak_reply_rate', 0.01);

        [$byBand, $byDay] = $this->taken($project, $weekStart, $timezone, $sending);

        return new GovernorVerdict(
            weekStart: $weekStart,
            timezone: $timezone,
            replyRate: $rate,
            measuredDays: $measuredDays,
            throttled: $throttled,
            weeklyAllowance: $throttled
                ? $this->int('throttled_weekly_ceiling', 2)
                : $this->int('weekly_ceiling', 5),
            dailyCeiling: $this->int('daily_ceiling', 2),
            weeklyFloor: $this->int('weekly_floor', 2),
            selectionFloor: $throttled
                ? $this->int('throttled_selection_floor', 70)
                : $this->int('selection_floor', 50),
            takenByBand: $byBand,
            takenByDay: $byDay,
        );
    }

    /**
     * Why this unit may not go out right now, or null if it may.
     *
     * The second half of this class's job, and the one that was missing: §10
     * says the mitigation for an account under automation is "архитектура, а не
     * дисциплина оператора", and a ceiling that only the weekly planner
     * consults is discipline. Three drafts slotted Monday, Wednesday and Friday
     * are approved in one sitting on Monday morning — every path out of that
     * screen goes through {@see PublishToChannels}, and until
     * this existed none of them asked anything, so all three landed on Monday
     * and §4.3's "≤2/день" was a comment.
     *
     * Two refusals, in the order an operator would want them:
     *
     * - the TTL of §5. A reactive draft that missed its window "убивается, а не
     *   публикуется позже", and the reaper that deletes it runs on a schedule —
     *   between the moment it expires and the moment it is deleted there is a
     *   window in which pressing publish would send exactly the thing §5 says
     *   is worse than silence. Nothing frees this one; it is dead, not delayed.
     * - the ceiling, asked of the same {@see GovernorVerdict} the planner uses,
     *   with the counting moved from the slot to the day of sending.
     *
     * Null for an article: the governor is about the social account's cadence
     * and an article has no cadence to burn. Null for the conversation band as
     * well — §1's second fact is that replies are the half of the channel that
     * works, and §5 marks the band "без счёта".
     */
    public function refusalToSend(ContentItem $unit, ?CarbonInterface $at = null): ?string
    {
        if (! $unit->isSocial()) {
            return null;
        }

        $band = $unit->social_band;

        if ($band !== null && ! $band->isCounted()) {
            return null;
        }

        $project = $unit->project;
        $now = CarbonImmutable::instance($at ?? now())->setTimezone($project->timezone);

        if ($unit->published_at === null && $unit->hasExpired($now)) {
            $expired = CarbonImmutable::instance($unit->expires_at ?? $now)
                ->setTimezone($project->timezone)
                ->format('D, j M Y H:i');

            return "this draft's window closed at {$expired} — §5 kills a reactive draft that "
                .'missed its window rather than publishing it late, and an automatic comment on '
                .'the day before yesterday is worse than saying nothing';
        }

        $verdict = $this->verdict($project, $now, sending: $unit);

        $refusal = $verdict->refusalFor($band, $now);

        if ($refusal === null) {
            return null;
        }

        $frees = $verdict->freesAt($band, $now);

        return $frees === null
            ? $refusal
            : $refusal.' — the earliest this frees is '.$frees->format('D, j M Y')
                ." ({$project->timezone})";
    }

    /**
     * Replies per impression over the trailing window, and how many days of it
     * were measurable.
     *
     * **Null means "not measured", and not measured does not throttle.** This
     * is the decision worth arguing, because `project_states` is empty until
     * 12.6 fills it and so every project reads as unmeasured today. Three
     * readings were available and only one survives contact with the rest of
     * the engine:
     *
     * Treating silence as a weak rate would run every project at the floor from
     * its first day — the weeks in which an account has no history at all are
     * exactly the weeks §1 says establish it, and a cadence of two is not
     * "2–5 постов в неделю", it is the bottom of the range chosen by a missing
     * API call. {@see ProjectState::replyRate()} already refused that reading
     * one level down, in as many words: "reporting 0.0 for an unmeasured day
     * would throttle a project for a missing API call". A governor that then
     * throttles on the null would restore the bug the model went out of its way
     * to prevent.
     *
     * Treating silence as a *good* rate is not what happens here either. The
     * unthrottled ceiling is five a week, which is the top of the range the
     * platform itself recommends and was chosen because volume is a liability
     * (§1); absence of evidence buys a project the ordinary ceiling, never more
     * than it. The protection against an integration that broke is the floor's
     * alert and the operator reading §7's summary, not a throttle that cannot
     * tell a broken gateway from a quiet audience.
     *
     * A single day is refused as well, through `min_measured_days`. One day's
     * rate is noise, and throttling a project for a Tuesday is the same mistake
     * in a smaller window.
     *
     * The rate is pooled — total replies over total impressions — rather than
     * averaged across daily rates. Averaging gives a day with three impressions
     * the same vote as a day with thirty thousand, and the days with three are
     * the ones where a single reply reads as 33%. What {@see ProjectState::replyRate()}
     * decides here is *whether a day counts at all*: it already refuses a
     * missing denominator, a negative one and a rate above 1, and re-deriving
     * those refusals from the raw columns is how two definitions start
     * disagreeing.
     *
     * @return array{float|null, int}
     */
    private function trailingReplyRate(Project $project, CarbonImmutable $at): array
    {
        $days = ProjectState::acrossProjects()
            ->where('project_id', $project->getKey())
            ->whereBetween('captured_on', [
                $at->subDays($this->int('trailing_days', 28))->toDateString(),
                $at->toDateString(),
            ])
            ->get();

        $replies = 0;
        $impressions = 0;
        $measured = 0;

        foreach ($days as $day) {
            if ($day->replyRate() === null) {
                continue;
            }

            $measured++;
            $replies += $day->post_replies ?? 0;
            $impressions += $day->post_impressions ?? 0;
        }

        if ($measured < $this->int('min_measured_days', 7) || $impressions <= 0) {
            return [null, $measured];
        }

        return [$replies / $impressions, $measured];
    }

    /**
     * What this project's week already holds, by band and by local day.
     *
     * Planned and published together, because the ceiling is about what will
     * exist by Sunday rather than about what exists now: a planner that counted
     * only published units would place five slots on Monday on top of the two
     * it placed last Monday, and discover the ceiling only as the week ran out.
     * `published_at` is consulted as well as `slot_at` so that a post published
     * outside a plan — by hand, or by an earlier phase — is still paid for.
     *
     * A unit whose TTL has passed and which never went live does not count. It
     * is already dead by §5 and `social:kill-expired` is about to delete it;
     * holding a slot open for it would let a stale reaction silence the rest of
     * the week.
     *
     * A unit with no band counts against the week and the day but against no
     * budget. There is no honest band to charge it to, and the two ceilings §1
     * gives — five a week, two a day — are about how much the account posts,
     * not about what the posts are for.
     *
     * **`$sending` changes which day a unit is charged to, and that is the
     * whole of the difference between planning and delivering.** A slot is a
     * promise about Wednesday; a delivery is Monday whatever the slot said. Ask
     * this in plan mode at the moment three drafts are approved together and
     * every one of them reads as a different day, because none of them has a
     * `published_at` yet — the unit is not marked published until a receiver
     * confirms (see {@see PublishToChannels::deliver()}), so
     * between queueing and confirmation a post that is on its way to Threads is
     * invisible to both columns. In sending mode a unit is charged to
     * `published_at`, else to the day its still-unsettled delivery was queued,
     * else to `slot_at` — which is what "≤2/день" (§1) is actually about — and
     * the unit being decided is left out, since it is the thing being counted
     * against the others rather than one of them.
     *
     * @return array{array<string, int>, array<string, int>}
     */
    private function taken(
        Project $project,
        CarbonImmutable $weekStart,
        string $timezone,
        ?ContentItem $sending = null,
    ): array {
        $weekEnd = $weekStart->addWeek();

        $inFlight = $sending === null ? [] : $this->inFlight($project, $weekStart, $weekEnd);

        $units = ContentItem::acrossProjects()
            ->where('project_id', $project->getKey())
            ->social()
            // Bound in UTC. `timestamptz` does not save this: Laravel formats a
            // bound date with `format('Y-m-d H:i:s')` and drops the offset, so
            // a local-time bind is read in the session timezone and the week
            // silently moves by the project's offset.
            ->where(function (QueryBuilder $query) use ($weekStart, $weekEnd, $inFlight): void {
                $query
                    ->whereBetween('slot_at', [$weekStart->utc(), $weekEnd->utc()])
                    ->orWhereBetween('published_at', [$weekStart->utc(), $weekEnd->utc()]);

                if ($inFlight !== []) {
                    // A unit with neither a slot nor a publication is not in the
                    // two columns above and is still on its way out the door.
                    $query->orWhereIn('id', array_keys($inFlight));
                }
            })
            ->get();

        $byBand = [];
        $byDay = [];

        foreach ($units as $unit) {
            if ($sending !== null && $unit->getKey() === $sending->getKey()) {
                continue;
            }

            if ($unit->hasExpired() && ! $unit->state->isLive()) {
                continue;
            }

            $at = $unit->published_at
                ?? $inFlight[(string) $unit->getKey()]
                ?? $unit->slot_at;

            if ($at === null) {
                continue;
            }

            $local = CarbonImmutable::instance($at)->setTimezone($timezone);

            if ($local->lessThan($weekStart) || ! $local->lessThan($weekEnd)) {
                continue;
            }

            $band = $unit->social_band;

            if ($band instanceof SocialBand && $band->isCounted()) {
                $byBand[$band->value] = ($byBand[$band->value] ?? 0) + 1;
            }

            if ($band instanceof SocialBand && ! $band->isCounted()) {
                // A reply is not a post. §1's second fact is that the accounts
                // which grow reply more than they write, and a cap on replying
                // is a cap on the half of the channel that works.
                continue;
            }

            $day = $local->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        return [$byBand, $byDay];
    }

    /**
     * Units already handed to a transport this week and not yet settled.
     *
     * Read from the delivery table rather than from the unit because the unit
     * says nothing while a delivery is in flight, on purpose: queueing is a
     * promise to try and §9's replay machinery needs it to stay one. The
     * consequence is that the ceiling has to look where the promise is
     * recorded.
     *
     * A dead-lettered delivery is not counted. It never reached the account, so
     * charging the day for it would spend the ceiling on a post nobody saw.
     *
     * @return array<string, CarbonImmutable> unit id => when it was queued
     */
    private function inFlight(Project $project, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $deliveries = WebhookDelivery::acrossProjects()
            ->where('project_id', $project->getKey())
            ->whereNotNull('content_item_id')
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Retrying->value])
            ->whereBetween('created_at', [$weekStart->utc(), $weekEnd->utc()])
            ->orderBy('created_at')
            ->get();

        $queued = [];

        foreach ($deliveries as $delivery) {
            $id = (string) $delivery->content_item_id;
            $at = $delivery->created_at;

            if ($at === null || isset($queued[$id])) {
                continue;
            }

            $queued[$id] = CarbonImmutable::instance($at);
        }

        return $queued;
    }

    private function int(string $key, int $default): int
    {
        $value = config("social.governor.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function float(string $key, float $default): float
    {
        $value = config("social.governor.{$key}", $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}
