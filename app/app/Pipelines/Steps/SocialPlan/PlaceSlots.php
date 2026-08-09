<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialPlan;

use App\Enums\ContentItemType;
use App\Enums\SocialBand;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\SocialListen\FeedPlanner;
use App\Social\GovernorVerdict;
use App\Support\Duty\DutyHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Where the week's posts stand, if any of them do (§4.3).
 *
 * > Слот ставится только туда, где оператор доступен следующие 60–90 минут: вес
 * > имеет скорость ответов в первый час, и пост в 03:00 хуже, чем отсутствие
 * > поста.
 *
 * Two rules and they are both refusals. The governor says how many and of which
 * band; the duty hours say when, and {@see DutyHours::covers()} is a
 * containment test rather than a point test — a window that closes ten minutes
 * after a candidate time does not qualify it, because the requirement is that
 * somebody is there for the *whole* of the next ninety minutes.
 *
 * **A project with no duty hours gets no slots.** Not a failure, not an
 * exception, and above all not a slot at a default hour: {@see DutyHours}
 * reads an empty configuration as never on duty precisely so that an unanswered
 * onboarding question cannot look like round-the-clock cover. The run returns a
 * sentence instead, and §7 puts it in front of the operator, who is the only
 * one who can fix it.
 *
 * **An empty week is a result, not a failure.** This step succeeds having
 * created nothing more often than not, and the payload it returns is then all
 * refusals. §4.3 is explicit that this is the difference between this pipeline
 * and a generator, and the calendar-filling instinct is switched off here in
 * code: there is no branch below that lowers a bar, borrows from next week's
 * budget, or places a slot outside a duty window.
 *
 * Nothing here writes text. The unit is created in `idea` with a band, a cause
 * and a time; `social_draft` is what fills it, and only for the slots that
 * survive to be drafted.
 */
class PlaceSlots extends AbstractStep
{
    /**
     * How finely the week is searched for a window, in minutes.
     *
     * Half-hourly rather than to the minute: the operator typed "09:00–13:00",
     * not a preference for 09:07, and a finer grid only makes the first
     * eligible instant of a window harder to predict from the configuration.
     */
    private const int GRANULARITY_MINUTES = 30;

    public static function key(): string
    {
        return 'place_slots';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [ReadGovernor::key(), GatherCandidates::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $verdict = $context->output(ReadGovernor::key(), GovernorPayload::class)->verdict;
        $gathered = $context->output(GatherCandidates::key(), SlotCandidatesPayload::class);

        $project = $context->project;
        $duty = $project->dutyHours();

        $refusals = [];

        foreach ($gathered->empty as $band => $reason) {
            $refusals[] = SocialBand::from($band)->label().': '.$reason;
        }

        if ($duty->isEmpty()) {
            // The whole run, refused in one sentence. §4.3: a post at 03:00 is
            // worse than no post, and a project that has never answered the
            // onboarding question of §11.4 is on duty at no hour at all.
            $refusals[] = 'No slots at all: this project has no duty hours, so there is no window where '
                .'somebody would be around for the first hour. §4.3 would rather publish nothing than '
                .'publish at 03:00. Answer the duty-hours question and the next run will plan.';

            return StepResult::success(new PlannedSlotsPayload($verdict, [], $refusals));
        }

        $slots = $this->windows($verdict, $duty, $project->timezone);

        if ($slots === []) {
            $refusals[] = 'No slots at all: no duty window left in this week is long enough to hold the '
                .config('social.governor.duty_window_minutes').' minutes §4.3 asks for after a post.';

            return StepResult::success(new PlannedSlotsPayload($verdict, [], $refusals));
        }

        /** @var array<int, SlotCandidate> $waiting */
        $waiting = $gathered->candidates;
        $placed = [];
        // Seeded rather than left null: `$slots` is non-empty by the guard
        // above, so the loop always sets it, and the refusal report below needs
        // *some* instant to ask the governor about.
        $lastSlot = $slots[0];

        foreach ($slots as $slot) {
            $lastSlot = $slot;

            if ($verdict->remaining() <= 0) {
                break;
            }

            foreach ($waiting as $index => $candidate) {
                if ($this->refuse($candidate, $verdict, $slot) !== null) {
                    continue;
                }

                $placed[] = $this->place($candidate, $slot, $project->timezone, $project->default_locale);
                $verdict = $verdict->withTaken($candidate->band, $slot);
                unset($waiting[$index]);

                continue 2;
            }
        }

        foreach ($waiting as $candidate) {
            // Every candidate that did not get a slot says why, by name. This
            // is §7's mandatory line at the grain that makes it useful: "the
            // week's ceiling is spent" and "this one was under the bar" are
            // different problems with different fixes.
            $refusals[] = $candidate->title.' — '.(
                $this->refuse($candidate, $verdict, $lastSlot)
                ?? 'the week ran out of duty windows before it came up'
            );
        }

        $context->remember('social_plan.placed', count($placed));

        return StepResult::success(new PlannedSlotsPayload($verdict, $placed, $refusals));
    }

    /**
     * Why this candidate may not have this slot, or null if it may.
     *
     * The selection bar is asked first and separately from the governor's
     * counting, because it is the half of the throttle that changes *what* gets
     * published rather than how much (§4.3: "срезает частоту и поднимает планку
     * отбора"). A week that only cut the count would publish the same weak
     * candidate it would have published anyway.
     */
    private function refuse(SlotCandidate $candidate, GovernorVerdict $verdict, CarbonImmutable $slot): ?string
    {
        if ($candidate->weight < $verdict->selectionFloor) {
            return "under the selection bar of {$verdict->selectionFloor} at weight {$candidate->weight}"
                .($verdict->throttled ? ' — a bar raised because the trailing reply rate is weak (§4.3)' : '');
        }

        // A reactive candidate whose window closes before the next moment
        // anybody is on duty. §5 kills a draft that misses its window; this is
        // the same rule one step earlier, where it costs nothing.
        if ($candidate->expiresAt !== null && ! $candidate->expiresAt->greaterThan($slot)) {
            return 'its window closes before the next time anybody is on duty, and §5 does not publish '
                .'a reaction late';
        }

        return $verdict->refusalFor($candidate->band, $slot);
    }

    /**
     * Every instant this week that a post could stand at, earliest first.
     *
     * Built from the duty hours rather than from a preferred posting time,
     * because §4.3 has no preferred posting time — it has a requirement that
     * somebody is around afterwards. Two constraints shape the list:
     *
     * **Only the future.** A planner that runs on Monday morning cannot put a
     * slot on Monday at 06:00.
     *
     * **At most `daily_ceiling` a day, spaced by the duty window.** The spacing
     * is the same number as the window on purpose: two posts whose first hours
     * overlap are competing for the same operator and, per §1, for the same
     * display window. It is also what keeps the daily ceiling from being
     * satisfied by two posts nine minutes apart.
     *
     * @return list<CarbonImmutable>
     */
    private function windows(GovernorVerdict $verdict, DutyHours $duty, string $timezone): array
    {
        $length = (int) config('social.governor.duty_window_minutes', 90);
        $now = CarbonImmutable::now($timezone);
        $slots = [];

        for ($day = 0; $day < 7; $day++) {
            $midnight = $verdict->weekStart->addDays($day);
            $taken = $verdict->takenByDay[$midnight->toDateString()] ?? 0;
            $previous = null;

            for ($minute = 0; $minute < 24 * 60; $minute += self::GRANULARITY_MINUTES) {
                if ($taken >= $verdict->dailyCeiling) {
                    break;
                }

                $at = $midnight->addMinutes($minute);

                if (! $at->greaterThan($now) || ! $duty->covers($at, $length, $timezone)) {
                    continue;
                }

                if ($previous !== null && $at->lessThan($previous->addMinutes($length))) {
                    continue;
                }

                $slots[] = $at;
                $previous = $at;
                $taken++;
            }
        }

        return $slots;
    }

    /**
     * One candidate, as a unit with a time on it.
     *
     * Two shapes, and the difference is §5's Derivative band: repurpose has
     * already written that one, so placing it is an update. Everything else is
     * a new row in `idea` with no body — the point of a slot is that
     * `social_draft` may still decide there is nothing good to say in it.
     */
    private function place(
        SlotCandidate $candidate,
        CarbonImmutable $slot,
        string $timezone,
        string $locale,
    ): string {
        $local = $slot->setTimezone($timezone);

        if ($candidate->unitId !== null) {
            $unit = ContentItem::query()->findOrFail($candidate->unitId);

            $unit->fill([
                'slot_at' => $slot->utc(),
                'scheduled_for' => $local->toDateString(),
            ])->save();

            return (string) $unit->getKey();
        }

        $unit = ContentItem::query()->create([
            // §3's whole reason for the signals table: the loop learns by
            // source, so a post that exists because somebody asked something
            // points at the asking.
            'signal_id' => $candidate->signalId,
            // The project's own language. A question may have been asked in
            // another one — `signals.raw` keeps the poster's words — but the
            // account answers in the language the account is in.
            'locale' => $locale,
            'type' => ContentItemType::SocialPost,
            'slug' => $this->slug($candidate->title),
            'title' => $candidate->title,
            'target_query' => $candidate->targetQuery,
            'entities' => $candidate->entities,
            'social_band' => $candidate->band,
            // The date for the calendar and the instant for the duty window,
            // written together so they cannot disagree.
            'scheduled_for' => $local->toDateString(),
            // `->utc()` and not the local instant. Laravel formats a bound
            // date with `format('Y-m-d H:i:s')` and drops the offset, so a
            // Lisbon 09:00 written to a `timestamptz` is read back by Postgres
            // in the session timezone — an hour out, which is exactly enough to
            // put a slot outside the duty window it was chosen for. The same
            // trap `Signal`'s docblock warns about.
            'slot_at' => $slot->utc(),
            // Only the reactive band carries one (§5), and it comes from the
            // signal rather than from a fixed offset: the news decides how long
            // it is still news.
            'expires_at' => $candidate->expiresAt?->utc(),
            'planned_derivatives' => [],
        ]);

        return (string) $unit->getKey();
    }

    /**
     * Unique per project, because the database says so.
     *
     * The same suffix loop {@see FeedPlanner}
     * uses, for the same reason: a slug made from a sentence is mostly stop
     * words and collides far more often than one made from a keyword.
     */
    private function slug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 80, '') ?: 'post';
        $slug = $base;
        $suffix = 2;

        while (ContentItem::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
