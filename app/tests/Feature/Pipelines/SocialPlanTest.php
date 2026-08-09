<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PipelineRunStatus;
use App\Enums\SignalKind;
use App\Enums\SocialBand;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectState;
use App\Models\Signal;
use App\Models\SocialPlan;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\SocialPlanPipeline;
use App\Pipelines\Steps\SocialPlan\PlaceSlots;
use App\Pipelines\Steps\SocialPlan\ReadGovernor;
use App\Social\Governor;
use App\Support\Seasonality\SeasonalCurve;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 12.5a — §4.3 and §5: the governor, the bands and the week's slots.
 *
 * The loudest test in this file is {@see a_weak_reply_rate_plans_a_quieter_week()},
 * because it is §12's third exit criterion written out: "неделя со слабым reply
 * rate автоматически уменьшает частоту следующей — **по тесту**". Everything
 * else here defends the same claim from a different side — that this pipeline
 * is an act of refusal rather than of production.
 *
 * The date is deliberate. `2026-08-24` is a Monday, and it is five whole weeks
 * before the first of October, which puts a subject peaking in October inside
 * {@see SeasonalCurve::PLAN_WEEKS_MIN}–`MAX` and a
 * subject peaking in August nowhere near it.
 *
 * The project runs in Lisbon rather than in UTC, so every assertion about a
 * slot is made in the operator's own timezone. §4.3's rule is about a human
 * being awake, and a test that agreed with the code only at zero offset would
 * pass for a project that posts at 03:00.
 */
final class SocialPlanTest extends TestCase
{
    use RefreshDatabase;

    /** The operator's timezone, one hour ahead of UTC in August. */
    private const string TIMEZONE = 'Europe/Lisbon';

    private Project $project;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 07:00 in Lisbon, with the whole working week ahead of it.
        Carbon::setTestNow('2026-08-24 06:00:00');

        config()->set('queue.default', 'sync');

        $this->project = Project::factory()->create([
            'timezone' => self::TIMEZONE,
            'duty_hours' => [
                'mon' => [['09:00', '18:00']],
                'tue' => [['09:00', '18:00']],
                'wed' => [['09:00', '18:00']],
                'thu' => [['09:00', '18:00']],
                'fri' => [['09:00', '18:00']],
            ],
            'original_data' => [],
        ]);

        app(CurrentProject::class)->set($this->project);

        Channel::factory()->threads()->create(['verified_at' => now()]);

        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------- §12.3, the exit criterion

    #[Test]
    public function a_weak_reply_rate_plans_a_quieter_week(): void
    {
        // §12's third exit criterion, and the whole reason the governor reads
        // `project_states` at all: "неделя со слабым reply rate автоматически
        // уменьшает частоту следующей — по тесту."
        $this->somethingToSay();
        $this->trailingReplyRate(replies: 50, impressions: 1_000);

        $healthy = $this->plan();

        $this->assertSame(PipelineRunStatus::Completed, $healthy->refresh()->status);
        $this->assertCount(5, $this->slots());

        // Same project, same pool, same duty hours — a different history.
        $this->reset();
        $this->somethingToSay();
        $this->trailingReplyRate(replies: 2, impressions: 1_000);

        $weak = $this->plan();

        $this->assertSame(PipelineRunStatus::Completed, $weak->refresh()->status);

        $slots = $this->slots();

        $this->assertCount(2, $slots);
        $this->assertLessThan(5, $slots->count());

        // Both halves of §4.3, not one. The frequency is cut *and* the bar is
        // raised: the derivative that cleared 50 does not clear 64, so a bad
        // week is not merely a shorter version of a good one — it publishes
        // different things. Cutting the count alone would have kept the same
        // weakest candidate and simply printed fewer of them.
        $verdict = app(Governor::class)->verdict($this->project);

        $this->assertTrue($verdict->throttled);
        $this->assertSame(64, $verdict->selectionFloor);

        $refusals = $this->payload($weak, PlaceSlots::key())['refusals'];

        $this->assertNotEmpty(array_filter(
            $refusals,
            static fn (string $refusal): bool => str_contains($refusal, 'selection bar of 64'),
        ));
    }

    #[Test]
    public function a_project_with_no_measurement_yet_keeps_the_ordinary_ceiling(): void
    {
        // `project_states` is empty until 12.6 fills it, so this is every
        // project today. Absence of evidence is not evidence of a weak rate:
        // `ProjectState::replyRate()` already refuses to report 0.0 for an
        // unmeasured day, and a governor that throttled on the null would
        // restore the bug one level up — every new account capped at the bottom
        // of §1's 2–5 range by a missing API call.
        $verdict = app(Governor::class)->verdict($this->project);

        $this->assertNull($verdict->replyRate);
        $this->assertFalse($verdict->throttled);
        $this->assertSame(5, $verdict->weeklyAllowance);
        $this->assertSame(50, $verdict->selectionFloor);
    }

    #[Test]
    public function one_measured_day_is_not_a_trailing_rate(): void
    {
        // A single day's rate is noise, and throttling a project for a Tuesday
        // is the same mistake in a smaller window.
        ProjectState::factory()->create(['post_impressions' => 1_000, 'post_replies' => 1]);

        $verdict = app(Governor::class)->verdict($this->project);

        $this->assertNull($verdict->replyRate);
        $this->assertFalse($verdict->throttled);
        $this->assertSame(1, $verdict->measuredDays);
    }

    // ----------------------------------------------------------- the ceiling

    #[Test]
    public function the_ceiling_holds_for_the_week_the_day_and_every_band(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);

        // Far more than the week can take, and five of them in one band.
        foreach (range(1, 5) as $n) {
            $this->question("Does vinegar shift limescale, attempt {$n}", weight: 90);
        }

        $this->news('Water charges rise in Lisbon', weight: 85, expiresInDays: 5);
        $this->ownData('average price per window');
        $this->seasonalSubject('Descaling before the winter', peakMonth: 10);
        $this->derivative('Three things we learned descaling kettles');

        $this->plan();

        $slots = $this->slots();

        // ≤5 a week (§4.3).
        $this->assertLessThanOrEqual(5, $slots->count());

        // ≤2 a day (§1: more than two a day cannibalises our own distribution).
        $perDay = $slots
            ->map(fn (ContentItem $unit): string => $unit->slot_at?->setTimezone(self::TIMEZONE)->toDateString() ?? '')
            ->countBy();

        foreach ($perDay as $day => $count) {
            $this->assertLessThanOrEqual(2, $count, "too many on {$day}");
        }

        // And never over a band's budget (§5: "недобор допустим, перебор — нет").
        $perBand = $slots->countBy(fn (ContentItem $unit): string => $unit->social_band->value);

        foreach ($perBand as $band => $count) {
            $budget = SocialBand::from((string) $band)->weeklyBudget();

            $this->assertNotNull($budget, "an uncounted band was planned as a slot: {$band}");
            $this->assertLessThanOrEqual($budget, $count, "over budget for {$band}");
        }

        $this->assertSame(2, $perBand[SocialBand::Question->value] ?? 0);
    }

    #[Test]
    public function a_week_that_is_already_full_gets_nothing_more(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);

        // Five already published this week, before the planner runs at all.
        foreach (range(1, 5) as $n) {
            ContentItem::factory()->create([
                'type' => ContentItemType::SocialPost,
                'social_band' => SocialBand::Question,
                'state' => ContentItemState::Published,
                'published_at' => Carbon::parse('2026-08-24 10:00:00'),
                'slot_at' => Carbon::parse('2026-08-24 10:00:00'),
            ]);
        }

        $this->somethingToSay();

        $this->plan();

        // The ceiling is about the week rather than about the run: a planner
        // that counted only what it placed would double an already full week.
        $this->assertCount(0, $this->slots()->whereNull('published_at'));
    }

    // ------------------------------------------------------------- the floor

    #[Test]
    public function a_week_under_the_floor_alerts_the_operator_and_invents_nothing(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);

        // Nothing in the pool at all. §4.3's floor is ≥2 a week, so this week
        // misses it.
        $run = $this->plan();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $plan = SocialPlan::forWeek($this->project);

        $this->assertNotNull($plan);
        $this->assertTrue($plan->hasAlert());
        $this->assertStringContainsString('below the floor', (string) $plan->alert);
        $this->assertSame(0, $plan->planned);

        // And **no filler post**. This is the assertion the whole phase is
        // about: the instinct of a content engine is to fill the calendar, and
        // §4.3 says it has to be switched off explicitly, in code.
        $this->assertSame(0, ContentItem::query()->social()->count());
    }

    #[Test]
    public function a_week_that_meets_the_floor_says_nothing(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);
        $this->question('Does vinegar shift limescale', weight: 90);
        $this->news('Water charges rise in Lisbon', weight: 85, expiresInDays: 5);

        $this->plan();

        $plan = SocialPlan::forWeek($this->project);

        $this->assertNotNull($plan);
        $this->assertSame(2, $plan->planned);
        $this->assertFalse($plan->hasAlert());
        $this->assertNull($plan->alert);
    }

    // ------------------------------------------------------------ duty hours

    #[Test]
    public function a_project_with_no_duty_hours_gets_no_slots_and_a_stated_reason(): void
    {
        $this->project->forceFill(['duty_hours' => null])->save();
        $this->trailingReplyRate(replies: 50, impressions: 1_000);
        $this->somethingToSay();

        $run = $this->plan();

        // Not an exception, not a failure, and above all not a slot at a
        // default hour: `DutyHours` reads an unanswered onboarding question as
        // never on duty precisely so it cannot look like round-the-clock cover.
        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        // Not one slot, and nothing new invented either: the only social row in
        // the project is the repurpose cut that was already there, and it still
        // has no time on it.
        $this->assertCount(0, $this->slots());
        $this->assertSame(1, ContentItem::query()->social()->count());

        $refusals = $this->payload($run, PlaceSlots::key())['refusals'];

        $this->assertNotEmpty(array_filter(
            $refusals,
            static fn (string $refusal): bool => str_contains($refusal, 'no duty hours'),
        ));

        // And it is retrievable where §7's daily summary will look for it,
        // rather than only inside a step's payload.
        $plan = SocialPlan::forWeek($this->project);

        $this->assertNotNull($plan);
        $this->assertNotEmpty(array_filter(
            $plan->reasons,
            static fn (array $reason): bool => str_contains($reason['detail'], 'no duty hours'),
        ));
    }

    #[Test]
    public function a_slot_needs_the_whole_window_ahead_of_it_not_just_its_start(): void
    {
        // One window, Monday only, closing at 10:40 — ten minutes after the
        // second half-hourly candidate at 10:30. `DutyHours::covers()` is a
        // containment test rather than a point test for exactly this: somebody
        // has to be there for the *whole* of the next ninety minutes, because
        // the weight is in the first hour's replies.
        $this->project->forceFill(['duty_hours' => ['mon' => [['09:00', '10:40']]]])->save();
        $this->trailingReplyRate(replies: 50, impressions: 1_000);

        $this->question('Does vinegar shift limescale', weight: 90);
        $this->question('What is the safest descaler for a kettle', weight: 89);

        $run = $this->plan();

        $slots = $this->slots();

        $this->assertCount(1, $slots);

        $at = $slots->first()?->slot_at?->setTimezone(self::TIMEZONE);

        $this->assertNotNull($at);
        $this->assertSame('2026-08-24 09:00', $at->format('Y-m-d H:i'));

        // 10:30 would end at 12:00 and the operator leaves at 10:40, so the
        // second candidate gets no window at all — and says so.
        $refusals = $this->payload($run, PlaceSlots::key())['refusals'];

        $this->assertNotEmpty(array_filter(
            $refusals,
            static fn (string $refusal): bool => str_contains($refusal, 'ran out of duty windows'),
        ));
    }

    #[Test]
    public function a_slot_never_lands_in_the_past(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);
        $this->somethingToSay();

        $this->plan();

        foreach ($this->slots() as $slot) {
            $this->assertTrue($slot->slot_at?->isFuture(), 'a slot was planned into the past');
        }
    }

    // --------------------------------------------------------------- seasons

    #[Test]
    public function a_seasonal_subject_is_planned_before_its_peak_and_not_at_it(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);

        // Five weeks before the first of October, which is inside §5's four to
        // six. The August one is already in its peak month: the work needed to
        // be live before demand rose, and posting into it is the seasonal band
        // pretending to be the reactive one.
        $this->seasonalSubject('Descaling before the winter', peakMonth: 10);
        $this->seasonalSubject('Hard water in the summer', peakMonth: 8);

        $this->plan();

        $seasonal = $this->slots()->where('social_band', SocialBand::Season);

        $this->assertCount(1, $seasonal);
        $this->assertSame('Descaling before the winter', $seasonal->first()?->title);
    }

    // -------------------------------------------------------------- the TTL

    #[Test]
    public function a_reactive_unit_carries_the_window_it_dies_in(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);
        $signal = $this->news('Water charges rise in Lisbon', weight: 85, expiresInDays: 3);

        $this->plan();

        $reaction = $this->slots()->firstWhere('social_band', SocialBand::Reaction);

        $this->assertNotNull($reaction);

        // The TTL rides on the unit and not only on the signal, because the
        // thing §5 kills is the draft.
        $this->assertNotNull($reaction->expires_at);
        $this->assertTrue($reaction->expires_at->equalTo($signal->expires_at));
        $this->assertTrue($reaction->slot_at?->lessThan($reaction->expires_at));
    }

    #[Test]
    public function a_reaction_whose_window_closes_before_the_next_duty_hour_is_never_planned(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);

        // The news dies at 08:00 Lisbon; nobody is on duty until 09:00. §5 does
        // not publish a reaction late, so the cheapest place to enforce that is
        // before the draft exists.
        $this->news('Water charges rise in Lisbon', weight: 85, expiresAt: '2026-08-24 07:00:00');

        $run = $this->plan();

        $this->assertCount(0, $this->slots()->where('social_band', SocialBand::Reaction));

        $refusals = $this->payload($run, PlaceSlots::key())['refusals'];

        $this->assertNotEmpty(array_filter(
            $refusals,
            static fn (string $refusal): bool => str_contains($refusal, 'its window closes before'),
        ));
    }

    #[Test]
    public function a_draft_past_its_window_is_killed_rather_than_published_late(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);
        $this->news('Water charges rise in Lisbon', weight: 85, expiresInDays: 2);

        $this->plan();

        $reaction = $this->slots()->firstWhere('social_band', SocialBand::Reaction);

        $this->assertNotNull($reaction);

        // A live post with a window that has also passed. Its TTL was about
        // whether it was still worth publishing, and it was published.
        $survivor = ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'social_band' => SocialBand::Reaction,
            'state' => ContentItemState::Published,
            'published_at' => Carbon::parse('2026-08-24 10:00:00'),
            'expires_at' => Carbon::parse('2026-08-25 10:00:00'),
        ]);

        // The window closes with the draft still sitting there.
        Carbon::setTestNow('2026-08-30 12:00:00');

        $this->console('social:kill-expired');

        $this->assertNull(ContentItem::query()->find($reaction->getKey()));
        $this->assertNotNull(ContentItem::query()->find($survivor->getKey()));

        // Killed is not silent. §7 asks what the engine did not do and why, so
        // the kill lands on the week the slot stood in.
        $plan = SocialPlan::forWeek($this->project, Carbon::parse('2026-08-24 12:00:00'));

        $this->assertNotNull($plan);
        $this->assertNotEmpty(array_filter(
            $plan->reasons,
            static fn (array $reason): bool => $reason['code'] === 'killed',
        ));
    }

    // -------------------------------------------------------- conversation

    #[Test]
    public function conversation_is_never_planned_as_a_slot(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);

        // A live reply, which is the raw material of the engage contour and of
        // nothing else.
        Signal::factory()->create([
            'kind' => SignalKind::Reply,
            'title' => 'somebody answered our post about limescale',
            'weight' => 95,
        ]);

        $this->somethingToSay();
        $this->plan();

        $this->assertCount(0, $this->slots()->where('social_band', SocialBand::Conversation));

        // And the governor refuses it outright rather than by arithmetic. This
        // is what `SocialBand::isCounted()` exists for: the obvious spelling —
        // `$taken >= $band->weeklyBudget()` — is `$taken >= 0` for a null
        // budget, so an empty week reads as "over budget" and the half of the
        // channel §1 values most is switched off by a type coercion.
        $verdict = app(Governor::class)->verdict($this->project);

        $this->assertNotNull($verdict->refusalFor(SocialBand::Conversation, Carbon::now()));
        $this->assertStringContainsString(
            'reply contour',
            (string) $verdict->refusalFor(SocialBand::Conversation, Carbon::now()),
        );

        // Nor is a reply charged against the ceiling when one happens.
        $before = $verdict->taken();

        $this->assertSame($before, $verdict->withTaken(SocialBand::Conversation, Carbon::now())->taken());
    }

    // -------------------------------------------------- the empty slot (§7)

    #[Test]
    public function an_empty_week_carries_a_reason_and_the_reason_is_retrievable(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);

        $run = $this->plan();

        $plan = SocialPlan::forWeek($this->project);

        $this->assertNotNull($plan);
        $this->assertSame(0, $plan->planned);

        // §7: "чего движок делать не стал и почему… Последняя строка
        // обязательна. Молчащий автомат неотличим от сломанного."
        $this->assertNotEmpty($plan->reasons);

        foreach ($plan->reasons as $reason) {
            $this->assertNotSame('', $reason['detail']);
        }

        // One line per band that had nothing, so the operator can tell "no
        // news this week" from "the pool is empty".
        $details = array_map(static fn (array $reason): string => $reason['detail'], $plan->reasons);

        $this->assertNotEmpty(array_filter(
            $details,
            static fn (string $detail): bool => str_starts_with($detail, 'Question:'),
        ));

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
    }

    #[Test]
    public function running_twice_in_one_week_updates_the_same_row(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);
        $this->question('Does vinegar shift limescale', weight: 90);

        $this->plan();
        $this->plan();

        // One week, one answer. Two rows would give §7 two versions of what the
        // engine decided, and the planner is idempotent through the unique key.
        $this->assertSame(1, SocialPlan::query()->count());

        // And the question is not planned twice: the unit already points at the
        // signal, which is what takes it out of the pool.
        $this->assertCount(1, $this->slots());
    }

    // ------------------------------------------------------------- schedule

    #[Test]
    public function planning_is_on_the_schedule_weekly_and_on_its_own(): void
    {
        /** @var list<Event> $events */
        $events = app(Schedule::class)->events();

        $expressions = [];

        foreach ($events as $event) {
            foreach (['social:plan', 'social:kill-expired'] as $command) {
                if (str_contains((string) $event->command, $command)) {
                    $expressions[$command] = $event->expression;
                }
            }
        }

        // Monday at 06:00, its own line. `EngineTickCommand::CONTOUR` is a rule
        // — each contour blocks only itself — and the week's slots feed none of
        // the six pipelines the tick runs.
        $this->assertSame('0 6 * * 1', $expressions['social:plan'] ?? null);
        $this->assertSame('0 * * * *', $expressions['social:kill-expired'] ?? null);
    }

    #[Test]
    public function the_command_skips_a_project_with_no_threads_channel(): void
    {
        Channel::query()->delete();

        $this->console('social:plan', ['project' => $this->project->slug]);

        // A plan is a list of times to publish at, and a project with nowhere
        // to publish does not need one — nor four step rows a week saying so.
        $this->assertSame(0, PipelineRun::query()->count());
    }

    #[Test]
    public function the_command_starts_one_run_for_a_connected_project(): void
    {
        $this->console('social:plan', ['project' => $this->project->slug]);

        $this->assertSame(1, PipelineRun::query()->where('pipeline', 'social_plan')->count());
    }

    // ------------------------------------------------------------- metering

    #[Test]
    public function the_run_is_metered_like_any_other(): void
    {
        $this->trailingReplyRate(replies: 50, impressions: 1_000);
        $this->somethingToSay();

        $run = $this->plan()->refresh();

        // Every step the pipeline declares, counted from the definition rather
        // than written here: what the literal protects is not the number, it is
        // "nothing runs unmetered".
        $this->assertSame(count((new SocialPlanPipeline)->steps()), $run->steps()->count());
        $this->assertSame((int) $run->steps()->sum('cost_micros'), $run->cost_micros);

        foreach ($run->steps()->get() as $step) {
            $this->assertNotNull($step->latency_ms);
        }

        // And free, because §4.3 requires the guard to be deterministic and
        // §8 costs the *published post* rather than the calls behind it. The
        // expensive model belongs to `social_draft`, and only for a slot this
        // pipeline decided was worth having.
        $this->assertSame(0, $run->cost_micros);
        $this->assertSame(0, $this->models->callCount());
    }

    #[Test]
    public function the_governor_records_why_the_ceiling_was_what_it_was(): void
    {
        $this->trailingReplyRate(replies: 2, impressions: 1_000);

        $run = $this->plan();

        // The verdict lands in the run, so "why did this week only get two" is
        // answerable months later without recomputing anything.
        $verdict = $this->payload($run, ReadGovernor::key());

        $this->assertTrue($verdict['throttled']);
        $this->assertSame(2, $verdict['weekly_allowance']);
        $this->assertSame(64, $verdict['selection_floor']);
        $this->assertEqualsWithDelta(0.002, $verdict['reply_rate'], 0.0001);
        $this->assertSame(14, $verdict['measured_days']);
    }

    // ----------------------------------------------------------------- setup

    /**
     * One candidate in each of the five plannable bands, comfortably above the
     * ordinary selection bar. Six candidates for five slots, so the ceiling is
     * always the binding constraint in a healthy week.
     */
    private function somethingToSay(): void
    {
        $this->question('Does vinegar shift limescale', weight: 90);
        $this->question('What is the safest descaler for a kettle', weight: 89);
        $this->news('Water charges rise in Lisbon', weight: 85, expiresInDays: 5);
        $this->ownData('average price per window');
        $this->seasonalSubject('Descaling before the winter', peakMonth: 10);
        $this->derivative('Three things we learned descaling kettles');
    }

    /**
     * A question somebody asked that the listening contour has already routed
     * to an article — which is the only kind the social planner takes.
     *
     * `FeedPlanner` commissions the page and does not consume the signal; this
     * band takes the subjects whose page does not exist yet, so the post stands
     * in the gap §6 says the article is on its way to fill. A question with no
     * article behind it is deliberately left alone: taking it would silently
     * cancel the article, which §1.3 calls the more valuable half.
     */
    private function question(string $title, int $weight): Signal
    {
        $signal = Signal::factory()->create([
            'kind' => SignalKind::Question,
            'title' => $title,
            'weight' => $weight,
            'expires_at' => Carbon::now()->addDays(14),
        ]);

        ContentItem::factory()->create([
            'signal_id' => $signal->getKey(),
            'title' => $title,
            'target_query' => $title,
            'state' => ContentItemState::Idea,
        ]);

        return $signal;
    }

    private function news(
        string $title,
        int $weight,
        ?int $expiresInDays = null,
        ?string $expiresAt = null,
    ): Signal {
        return Signal::factory()->create([
            'kind' => SignalKind::News,
            'title' => $title,
            'weight' => $weight,
            'occurred_at' => Carbon::now()->subHours(2),
            'expires_at' => $expiresAt !== null
                ? Carbon::parse($expiresAt)
                : Carbon::now()->addDays($expiresInDays ?? 2),
        ]);
    }

    private function ownData(string $fact): void
    {
        $this->project->forceFill([
            'original_data' => [...$this->project->original_data, $fact => 'something true'],
        ])->save();

        $this->project->refresh();
        app(CurrentProject::class)->set($this->project);
    }

    /** A subject whose demand peaks in one month and is flat in the others. */
    private function seasonalSubject(string $title, int $peakMonth): void
    {
        $volumes = array_fill_keys(range(1, 12), 100);
        $volumes[$peakMonth] = 1_000;

        ContentItem::factory()->create([
            'title' => $title,
            'target_query' => $title,
            'monthly_volumes' => $volumes,
            'state' => ContentItemState::Idea,
        ]);
    }

    /**
     * Something the repurpose pipeline already cut, with no time on it.
     *
     * The Derivative band schedules rather than writes: repurpose saves the
     * `SocialPost` row, and creating a second one here would publish the same
     * article twice and charge §5's budget twice.
     */
    private function derivative(string $title): ContentItem
    {
        $parent = ContentItem::factory()->create([
            'state' => ContentItemState::Published,
            'published_at' => Carbon::now()->subWeek(),
        ]);

        return ContentItem::factory()->create([
            'parent_id' => $parent->getKey(),
            'type' => ContentItemType::SocialPost,
            'social_band' => SocialBand::Derivative,
            'title' => $title,
            'target_query' => $title,
            'state' => ContentItemState::Idea,
        ]);
    }

    /** A fortnight of measured days at one rate. */
    private function trailingReplyRate(int $replies, int $impressions): void
    {
        ProjectState::factory()->count(14)->create([
            'post_impressions' => $impressions,
            'post_replies' => $replies,
        ]);
    }

    /** Everything this project holds, so a second week starts from nothing. */
    private function reset(): void
    {
        ContentItem::query()->delete();
        Signal::query()->delete();
        SocialPlan::query()->delete();
        ProjectState::query()->delete();
        PipelineRun::query()->delete();

        $this->project->forceFill(['original_data' => []])->save();
        $this->project->refresh();
        app(CurrentProject::class)->set($this->project);
    }

    private function plan(): PipelineRun
    {
        return app(PipelineRunner::class)->start('social_plan', $this->project);
    }

    /**
     * @return Collection<int, ContentItem>
     */
    private function slots(): Collection
    {
        return ContentItem::query()
            ->social()
            ->whereNotNull('slot_at')
            ->orderBy('slot_at')
            ->get();
    }

    /**
     * `artisan()` is declared as returning `PendingCommand|int`, and
     * assertSuccessful() only records the expectation — the command runs in
     * __destruct(), so it has to be run explicitly.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function console(string $command, array $arguments = []): void
    {
        /** @var PendingCommand $pending */
        $pending = $this->artisan($command, $arguments);

        $pending->assertSuccessful()->run();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PipelineRun $run, string $stepKey): array
    {
        $output = $this->step($run, $stepKey)->output;

        return is_array($output) ? $output : [];
    }

    private function step(PipelineRun $run, string $stepKey): PipelineStep
    {
        return $run->steps()->where('step_key', $stepKey)->firstOrFail();
    }
}
