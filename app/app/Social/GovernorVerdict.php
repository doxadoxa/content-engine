<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\SocialBand;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the governor decided for one project's week (§4.3, §5).
 *
 * A value rather than a service call per question, because every question the
 * planner asks is about the same week and the answers have to agree with each
 * other: "may this band go" and "is the week under its floor" read the same
 * counters, and re-querying between them would let a slot placed a line earlier
 * be invisible to the next check.
 *
 * Immutable, and {@see withTaken()} returns a new one. The planner places a
 * slot and carries the successor forward, so the arithmetic of "what is left"
 * is the governor's rather than the caller's — which is the whole reason this
 * is a shared object and not a handful of integers passed around.
 *
 * Nothing here calls a model. §4.3 says the guard is "детерминированный, без
 * вызова модели", and a ceiling that occasionally hallucinates is not one.
 */
final readonly class GovernorVerdict
{
    /**
     * @param  string  $timezone  the project's, so a "day" is the operator's day
     * @param  float|null  $replyRate  null when the trailing window is not measurable
     * @param  int  $measuredDays  how many days of `project_states` carried a usable rate
     * @param  array<string, int>  $takenByBand  band value => units already accounted for this week
     * @param  array<string, int>  $takenByDay  local Y-m-d => units already accounted for that day
     */
    public function __construct(
        public CarbonImmutable $weekStart,
        public string $timezone,
        public ?float $replyRate,
        public int $measuredDays,
        public bool $throttled,
        public int $weeklyAllowance,
        public int $dailyCeiling,
        public int $weeklyFloor,
        public int $selectionFloor,
        public array $takenByBand = [],
        public array $takenByDay = [],
    ) {}

    /** Every counted unit this week already holds, planned or published. */
    public function taken(): int
    {
        return array_sum($this->takenByDay);
    }

    /** How many more the week may hold. Never negative: a week may be over. */
    public function remaining(): int
    {
        return max(0, $this->weeklyAllowance - $this->taken());
    }

    /**
     * Why this band may not have a slot at this instant, or null if it may.
     *
     * A sentence and not a boolean, because §7 makes the explanation mandatory
     * — "чего движок делать не стал и почему" — and a refusal that has to be
     * reconstructed by whoever renders the summary is a refusal that will be
     * rendered as "no". Every branch below is a line an operator can read.
     *
     * The order is deliberate: category first, then the week, then the band,
     * then the day. It reports the *largest* reason a slot did not happen,
     * which is the one worth acting on — "the week is full" is actionable and
     * "Tuesday is full" is noise when the week is full anyway.
     *
     * The band is nullable because the delivery boundary of
     * {@see Governor::refusalToSend()} asks the same question about a unit that
     * carries no band. {@see Governor::taken()} already decided what such a
     * unit costs — the week and the day, no band budget — and this restates
     * that decision rather than inventing a second one: a null band skips the
     * budget branch and nothing else. A post with no honest band still cannot
     * be the third one today.
     */
    public function refusalFor(?SocialBand $band, CarbonInterface $at): ?string
    {
        // Asked before the number, and this is the guard {@see SocialBand::isCounted()}
        // exists for. Conversation's budget is null; `$taken >= null` is
        // `$taken >= 0`, which is true on an empty week, so the naive spelling
        // shuts off the half of the channel §1 values most — and the planner
        // would read the refusal as "budget spent" rather than as what it is.
        if ($band !== null && ! $band->isCounted()) {
            return 'the conversation band is never a planned slot — it is the reply contour of §4.2, '
                .'uncounted and answered by a person';
        }

        if ($this->taken() >= $this->weeklyAllowance) {
            return $this->throttled
                ? "the week's ceiling of {$this->weeklyAllowance} is spent — cut from the usual "
                    .'because the trailing reply rate is below the threshold (§4.3)'
                : "the week's ceiling of {$this->weeklyAllowance} is spent (§4.3)";
        }

        if ($band !== null) {
            // Non-null by isCounted(), stated for the reader and for the analyser.
            $budget = $band->weeklyBudget() ?? 0;

            if (($this->takenByBand[$band->value] ?? 0) >= $budget) {
                return "the {$band->label()} band's weekly budget of {$budget} is spent (§5)";
            }
        }

        $day = $this->day($at);

        if (($this->takenByDay[$day] ?? 0) >= $this->dailyCeiling) {
            return "{$day} already has {$this->dailyCeiling} — more than two a day cannibalises "
                .'our own distribution (§1)';
        }

        return null;
    }

    /**
     * When the limit that refused this band next lifts.
     *
     * §7 budgets five minutes for the day, and "we did not publish it" without
     * "and here is when we can" costs the operator the rest of the morning
     * working out whether to intervene. The branches mirror
     * {@see refusalFor()} exactly and in the same order, so the sentence and
     * the date always name the same limit: a week that is spent frees on the
     * next Monday, a day that is full frees at the next local midnight.
     *
     * Null when nothing here refused — the caller has no date to print because
     * there is nothing to wait for.
     */
    public function freesAt(?SocialBand $band, CarbonInterface $at): ?CarbonImmutable
    {
        if ($band !== null && ! $band->isCounted()) {
            return null;
        }

        $nextWeek = $this->weekStart->addWeek();

        if ($this->taken() >= $this->weeklyAllowance) {
            return $nextWeek;
        }

        if ($band !== null && ($this->takenByBand[$band->value] ?? 0) >= ($band->weeklyBudget() ?? 0)) {
            return $nextWeek;
        }

        if (($this->takenByDay[$this->day($at)] ?? 0) >= $this->dailyCeiling) {
            return CarbonImmutable::instance($at)->setTimezone($this->timezone)->addDay()->startOfDay();
        }

        return null;
    }

    /** The same verdict with one more unit of this band on this day. */
    public function withTaken(SocialBand $band, CarbonInterface $at): self
    {
        if (! $band->isCounted()) {
            // Replies are not budgeted (§1, fact 2), so recording one would put
            // the conversation contour inside a ceiling it is exempt from.
            return $this;
        }

        $byBand = $this->takenByBand;
        $byBand[$band->value] = ($byBand[$band->value] ?? 0) + 1;

        $day = $this->day($at);
        $byDay = $this->takenByDay;
        $byDay[$day] = ($byDay[$day] ?? 0) + 1;

        return new self(
            weekStart: $this->weekStart,
            timezone: $this->timezone,
            replyRate: $this->replyRate,
            measuredDays: $this->measuredDays,
            throttled: $this->throttled,
            weeklyAllowance: $this->weeklyAllowance,
            dailyCeiling: $this->dailyCeiling,
            weeklyFloor: $this->weeklyFloor,
            selectionFloor: $this->selectionFloor,
            takenByBand: $byBand,
            takenByDay: $byDay,
        );
    }

    /** Under the floor of §4.3, which is an alert and never a filler post. */
    public function missesFloor(): bool
    {
        return $this->taken() < $this->weeklyFloor;
    }

    public function shortfall(): int
    {
        return max(0, $this->weeklyFloor - $this->taken());
    }

    /**
     * The line §7's daily summary shows an operator, or null when there is
     * nothing to say.
     *
     * Deliberately never a suggestion to publish something. §4.3's floor is
     * "недобор — алерт оператору, а не тишина", and the third possible reading
     * — недобор means the engine writes something to fill the gap — is the one
     * the whole spec is against.
     */
    public function alert(): ?string
    {
        if (! $this->missesFloor()) {
            return null;
        }

        $week = $this->weekStart->toDateString();
        $taken = $this->taken();

        return "Presence is below the floor for the week of {$week}: {$taken} of {$this->weeklyFloor} "
            .'planned. The engine will not invent a post to fill it (§4.3) — either there was nothing '
            .'worth saying, or the duty hours leave nowhere to say it.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'week_start' => $this->weekStart->toIso8601String(),
            'timezone' => $this->timezone,
            'reply_rate' => $this->replyRate,
            'measured_days' => $this->measuredDays,
            'throttled' => $this->throttled,
            'weekly_allowance' => $this->weeklyAllowance,
            'daily_ceiling' => $this->dailyCeiling,
            'weekly_floor' => $this->weeklyFloor,
            'selection_floor' => $this->selectionFloor,
            'taken_by_band' => $this->takenByBand,
            'taken_by_day' => $this->takenByDay,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, int> $byBand */
        $byBand = is_array($data['taken_by_band'] ?? null) ? $data['taken_by_band'] : [];
        /** @var array<string, int> $byDay */
        $byDay = is_array($data['taken_by_day'] ?? null) ? $data['taken_by_day'] : [];

        return new self(
            weekStart: CarbonImmutable::parse((string) ($data['week_start'] ?? 'now')),
            timezone: (string) ($data['timezone'] ?? 'UTC'),
            replyRate: isset($data['reply_rate']) && is_numeric($data['reply_rate'])
                ? (float) $data['reply_rate']
                : null,
            measuredDays: (int) ($data['measured_days'] ?? 0),
            throttled: (bool) ($data['throttled'] ?? false),
            weeklyAllowance: (int) ($data['weekly_allowance'] ?? 0),
            dailyCeiling: (int) ($data['daily_ceiling'] ?? 0),
            weeklyFloor: (int) ($data['weekly_floor'] ?? 0),
            selectionFloor: (int) ($data['selection_floor'] ?? 0),
            takenByBand: array_map(intval(...), $byBand),
            takenByDay: array_map(intval(...), $byDay),
        );
    }

    /** The operator's calendar day, not UTC's. */
    private function day(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone($this->timezone)->toDateString();
    }
}
