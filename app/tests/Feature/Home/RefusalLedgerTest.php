<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\ChannelType;
use App\Enums\ContentItemType;
use App\Enums\InteractionSkipReason;
use App\Enums\InteractionState;
use App\Enums\SocialBand;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\SocialPlan;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §7's last line, on the screen it moved to.
 *
 * > Молчащий автомат неотличим от сломанного, и единственная вещь, которая
 * > делает «сегодня не публикуем» приемлемой, — объяснение рядом.
 *
 * This was `DailySummaryTest`, against a `/today` page that no longer exists —
 * it was one of three landing screens that all read as "dashboard", and the two
 * parts of it that existed nowhere else moved onto Home. The guarantees did not
 * move: the loudest test here is still
 * {@see the_last_line_is_there_when_the_engine_did_nothing_at_all()} and its
 * pair, because §7 makes that line mandatory in as many words.
 *
 * One guarantee is **new**, and it is the reason moving the line is an
 * improvement rather than a burial: {@see a_catastrophe_does_not_render_like_an_all_clear()}.
 * The old screen drew "The week was never planned" and "Nothing was refused
 * this week" in the same rose-coloured card with the same icon, so telling a
 * disaster from an all-clear meant reading prose.
 */
final class RefusalLedgerTest extends TestCase
{
    use RefreshDatabase;

    /** Mid-morning, mid-week: Wednesday 12 August 2026, 10:00 UTC. */
    private const string NOW = '2026-08-12 10:00:00';

    private Project $project;

    private User $member;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW));

        $this->project = Project::factory()->create(['timezone' => 'Europe/Lisbon']);

        // A member, not the owner. §7 is the operator's morning routine, and
        // the screen is behind the same permission as `content.approve`.
        $this->member = User::factory()->create();
        $this->member->projects()->attach($this->project, ['role' => 'operator']);

        app(CurrentProject::class)->set($this->project);

        $this->channel = Channel::factory()->create([
            'type' => ChannelType::Threads,
            'config' => ['user_id' => '17841400000000000'],
        ]);
    }

    #[Test]
    public function the_ledger_is_never_deferred(): void
    {
        $this->plan(['planned' => 3]);

        // The whole point of the band. A mandatory line that arrives on a
        // second round trip renders as silence for as long as anybody actually
        // looks at the screen, which is the one thing §7 forbids.
        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('refusals.summary')
                ->has('refusals.severity')
                ->etc()
            );
    }

    #[Test]
    public function the_last_line_explains_an_empty_week_rather_than_hiding_it(): void
    {
        $this->plan([
            'planned' => 0,
            'reasons' => [
                $this->reason('empty_slot', 'Tuesday 09:00 — nothing in the pool scored above 50.'),
                $this->reason('killed', '“Rate cut today” missed its window and was killed (§5).'),
            ],
        ]);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('refusals.nothing_to_report', false)
                ->has('refusals.entries', 2)
                ->where('refusals.entries.0.code', 'empty_slot')
                ->where('refusals.entries.0.label', 'Slot left empty')
                ->where(
                    'refusals.entries.0.detail',
                    'Tuesday 09:00 — nothing in the pool scored above 50.',
                )
                ->where('refusals.entries.1.code', 'killed')
                ->etc()
            );
    }

    #[Test]
    public function the_last_line_is_there_when_the_engine_did_nothing_at_all(): void
    {
        // A week the planner recorded, in which it refused nothing, killed
        // nothing, throttled nothing and skipped nobody. The one shape in which
        // a lazier screen would render an empty panel — which §7 forbids,
        // because a silent machine is indistinguishable from a broken one.
        $this->plan(['planned' => 3]);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->where('refusals.nothing_to_report', true)
                    ->has('refusals.entries', 0)
                    ->where('refusals.alert', null)
                    ->where('refusals.throttle', null)
                    ->etc();

                $summary = $page->toArray()['props']['refusals']['summary'];

                // The band says so in words. This is the assertion §7 is
                // actually about: not that it is empty, but that it speaks.
                $this->assertIsString($summary);
                $this->assertNotSame('', trim($summary));
                $this->assertStringContainsString('Nothing was refused', $summary);
            });
    }

    #[Test]
    public function a_catastrophe_does_not_render_like_an_all_clear(): void
    {
        // A project that posts, and no plan row for the week: the planner did
        // not run. The band is collapsed by default on a screen that leads with
        // a composer, so "there is something in here" has to be legible without
        // opening it — otherwise moving §7's line really would bury it.
        ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'social_band' => SocialBand::Question,
        ]);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('refusals.severity', 'alarm')
                ->etc()
            );

        // And the ordinary refusal — a slot the engine declined to fill — is
        // the engine working as designed, so it stays quiet.
        SocialPlan::query()->delete();
        $this->plan([
            'planned' => 2,
            'reasons' => [$this->reason('empty_slot', 'Nothing scored above 50.')],
        ]);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('refusals.severity', 'noted')
                ->etc()
            );
    }

    #[Test]
    public function a_clean_week_says_so_without_raising_its_voice(): void
    {
        $this->plan(['planned' => 3]);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('refusals.severity', 'clear')
                ->etc()
            );
    }

    #[Test]
    public function a_week_nobody_planned_is_itself_reported_on_a_project_that_posts(): void
    {
        // No plan row at all, and a Threads channel on the project. "Nobody
        // decided anything" is not the same fact as "the engine decided to
        // publish nothing", and the difference is the whole of §7's last line.
        ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'social_band' => SocialBand::Question,
        ]);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('refusals.nothing_to_report', false)
                ->where('refusals.entries.0.code', 'not_planned')
                ->etc()
            );
    }

    #[Test]
    public function a_throttled_week_shows_the_throttle_and_the_reason_for_it(): void
    {
        $this->plan([
            'planned' => 2,
            'ceiling' => 2,
            'reply_rate' => 0.004,
            'throttled' => true,
            'selection_floor' => 70,
        ]);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->where('refusals.throttle.reply_rate', 0.004)
                    ->where('refusals.throttle.ceiling', 2)
                    ->where('refusals.throttle.selection_floor', 70)
                    ->where('refusals.entries.0.code', 'throttled')
                    ->etc();

                $detail = $page->toArray()['props']['refusals']['throttle']['detail'];

                $this->assertIsString($detail);
                // The rate is on the screen, not merely the fact of a cut: an
                // operator asked to accept a quieter week is owed the number
                // that bought it.
                $this->assertStringContainsString('0.40%', $detail);
                $this->assertStringContainsString('2 posts', $detail);
            });
    }

    #[Test]
    public function the_floor_alert_reaches_the_band(): void
    {
        $this->plan([
            'planned' => 0,
            'alert' => 'Presence is below the floor for the week of 2026-08-10: 0 of 2 planned.',
        ]);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('refusals.entries.0.code', 'floor')
                ->where('refusals.alert', 'Presence is below the floor for the week of 2026-08-10: 0 of 2 planned.')
                ->etc()
            );
    }

    #[Test]
    public function a_conversation_the_operator_skipped_is_on_the_same_list(): void
    {
        $this->plan();

        $conversation = $this->conversation(waitedMinutes: 200);
        $conversation->ignore(InteractionSkipReason::NotForUs->value);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('refusals.nothing_to_report', false)
                ->where('refusals.entries.0.code', 'skipped')
                ->where('refusals.entries.0.label', 'Conversation skipped')
                ->etc()
            );
    }

    #[Test]
    public function the_band_is_scoped_to_the_current_project(): void
    {
        $other = Project::factory()->create();

        app(CurrentProject::class)->run($other, function (): void {
            $this->plan([
                'planned' => 0,
                'reasons' => [$this->reason('empty_slot', 'Another project’s empty slot.')],
            ]);
        });

        $this->plan(['planned' => 3]);

        $this->actingAs($this->member)
            ->get(route('home.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('refusals.nothing_to_report', true)
                ->etc()
            );
    }

    #[Test]
    public function the_screen_needs_a_signed_in_operator(): void
    {
        $this->get(route('home.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- setup

    private function conversation(
        int $waitedMinutes,
        InteractionState $state = InteractionState::New,
        string $author = 'Somebody',
    ): Interaction {
        return Interaction::factory()->inState($state)->create([
            'channel_id' => $this->channel->getKey(),
            'project_id' => $this->project->getKey(),
            'author' => $author,
            'received_at' => CarbonImmutable::parse(self::NOW)->subMinutes($waitedMinutes),
        ]);
    }

    /**
     * The week's row, as the planner would have left it.
     *
     * Written directly rather than through {@see SocialPlan::record()} — the
     * governor's arithmetic is `SocialPlanTest`'s subject, and this file is
     * about what the band does with the row once it exists.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function plan(array $attributes = []): SocialPlan
    {
        /** @var SocialPlan $plan */
        $plan = SocialPlan::query()->create([
            'week_start' => SocialPlan::weekStart($this->project, CarbonImmutable::parse(self::NOW))
                ->toDateString(),
            'ceiling' => 5,
            'floor' => 2,
            'planned' => 3,
            'reply_rate' => 0.03,
            'throttled' => false,
            'selection_floor' => 50,
            'alert' => null,
            'reasons' => [],
            ...$attributes,
        ]);

        return $plan;
    }

    /**
     * One refusal in the shape {@see SocialPlan::note()} writes.
     *
     * @return array{code: string, detail: string, at: string}
     */
    private function reason(string $code, string $detail): array
    {
        return [
            'code' => $code,
            'detail' => $detail,
            'at' => CarbonImmutable::parse(self::NOW)->toIso8601String(),
        ];
    }
}
