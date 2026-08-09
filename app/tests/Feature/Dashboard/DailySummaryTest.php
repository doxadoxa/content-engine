<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\InteractionSkipReason;
use App\Enums\InteractionState;
use App\Enums\SocialBand;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\ProjectState;
use App\Models\SocialPlan;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §7 — one summary and five minutes.
 *
 * The loudest test in this file is {@see the_last_line_is_there_when_the_engine_did_nothing_at_all()}
 * and its pair. §7 makes that line mandatory in as many words — "молчащий
 * автомат неотличим от сломанного" — so a summary that renders three sections
 * and a hole where the fourth would be has failed at the thing the whole
 * feature was for, and no amount of the other three makes up for it.
 */
final class DailySummaryTest extends TestCase
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

    // ------------------------------------------------------------ the screen

    #[Test]
    public function the_summary_shows_all_four_sections_of_paragraph_seven(): void
    {
        $this->conversation(waitedMinutes: 95);
        $this->conversation(waitedMinutes: 4, state: InteractionState::Drafted);
        $this->slot();
        $this->plan(['reasons' => [$this->reason('empty_slot', 'Nothing in the pool cleared the bar.')]]);

        $this->actingAs($this->member)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('today/index')
                ->where('project.name', $this->project->name)
                // 1 — what happened.
                ->where('happened.total', 2)
                ->has('happened.conversations', 2)
                ->has('happened.buckets', 3)
                // 2 — what awaits you.
                ->where('awaiting.reply_drafts.count', 1)
                ->where('awaiting.post_of_the_day.title', 'The post of the day')
                // 4 — what the engine did not do, and why.
                ->where('not_done.nothing_to_report', false)
                ->where('not_done.entries.0.code', 'empty_slot')
                ->etc()
            );

        // 3 — what changed, deferred because it reads a month of snapshots.
        $this->actingAs($this->member)
            ->withHeaders($this->partial('changed'))
            ->get(route('today.index'))
            ->assertOk()
            ->assertJsonPath('props.changed.0.key', 'brand_demand')
            ->assertJsonPath('props.changed.1.key', 'direct_traffic')
            ->assertJsonPath('props.changed.2.key', 'reply_rate');
    }

    #[Test]
    public function the_summary_is_scoped_to_the_current_project(): void
    {
        $this->conversation(waitedMinutes: 20);

        $other = Project::factory()->create();
        app(CurrentProject::class)->run($other, function () use ($other): void {
            $channel = Channel::factory()->create([
                'type' => ChannelType::Threads,
                'config' => ['user_id' => '17841499999999999'],
            ]);

            Interaction::factory()->count(4)->create([
                'channel_id' => $channel->getKey(),
                'project_id' => $other->getKey(),
                'received_at' => now()->subHours(6),
            ]);
        });

        app(CurrentProject::class)->set($this->project);

        $this->actingAs($this->member)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('happened.total', 1)
                ->etc()
            );
    }

    #[Test]
    public function the_summary_needs_a_signed_in_operator(): void
    {
        $this->get(route('today.index'))->assertRedirect(route('login'));
    }

    // ------------------------------------------- the last line, which is why

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
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('not_done.nothing_to_report', false)
                ->has('not_done.entries', 2)
                ->where('not_done.entries.0.code', 'empty_slot')
                ->where('not_done.entries.0.label', 'Slot left empty')
                ->where(
                    'not_done.entries.0.detail',
                    'Tuesday 09:00 — nothing in the pool scored above 50.',
                )
                ->where('not_done.entries.1.code', 'killed')
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
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->where('not_done.nothing_to_report', true)
                    ->has('not_done.entries', 0)
                    ->where('not_done.alert', null)
                    ->where('not_done.throttle', null)
                    ->etc();

                $summary = $page->toArray()['props']['not_done']['summary'];

                // The section says so in words. This is the assertion §7 is
                // actually about: not that the panel is empty, but that it
                // speaks.
                $this->assertIsString($summary);
                $this->assertNotSame('', trim($summary));
                $this->assertStringContainsString('Nothing was refused', $summary);
            });
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
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('not_done.nothing_to_report', false)
                ->where('not_done.entries.0.code', 'not_planned')
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
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->where('not_done.throttle.reply_rate', 0.004)
                    ->where('not_done.throttle.ceiling', 2)
                    ->where('not_done.throttle.selection_floor', 70)
                    ->where('not_done.entries.0.code', 'throttled')
                    ->etc();

                $detail = $page->toArray()['props']['not_done']['throttle']['detail'];

                $this->assertIsString($detail);
                // The rate is on the screen, not merely the fact of a cut: an
                // operator asked to accept a quieter week is owed the number
                // that bought it.
                $this->assertStringContainsString('0.40%', $detail);
                $this->assertStringContainsString('2 posts', $detail);
            });
    }

    #[Test]
    public function the_floor_alert_reaches_the_summary(): void
    {
        $this->plan([
            'planned' => 0,
            'alert' => 'Presence is below the floor for the week of 2026-08-10: 0 of 2 planned.',
        ]);

        $this->actingAs($this->member)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('not_done.entries.0.code', 'floor')
                ->where('not_done.alert', 'Presence is below the floor for the week of 2026-08-10: 0 of 2 planned.')
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
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('not_done.nothing_to_report', false)
                ->where('not_done.entries.0.code', 'skipped')
                ->where('not_done.entries.0.label', 'Conversation skipped')
                ->etc()
            );
    }

    // ------------------------------------------------------------- urgency

    #[Test]
    public function conversations_are_ordered_by_how_long_they_have_waited(): void
    {
        $this->conversation(waitedMinutes: 5, author: 'just now');
        $this->conversation(waitedMinutes: 240, author: 'four hours');
        $this->conversation(waitedMinutes: 30, author: 'half an hour');

        $this->actingAs($this->member)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Longest wait at the top. §4.2 is judged on the first hour, so
                // urgency *is* how long somebody has been waiting.
                ->where('happened.conversations.0.author', 'four hours')
                ->where('happened.conversations.1.author', 'half an hour')
                ->where('happened.conversations.2.author', 'just now')
                ->where('happened.longest_wait_seconds', 240 * 60)
                // And read out by urgency band, which is the same order counted.
                ->where('happened.buckets.0.key', 'overdue')
                ->where('happened.buckets.0.count', 1)
                ->where('happened.buckets.1.count', 1)
                ->where('happened.buckets.2.count', 1)
                ->etc()
            );
    }

    #[Test]
    public function the_wait_on_the_summary_agrees_with_the_reply_queue(): void
    {
        $conversation = $this->conversation(waitedMinutes: 42);

        $summary = $this->actingAs($this->member)->get(route('today.index'))->assertOk();
        $queue = $this->actingAs($this->member)->get(route('engage.index'))->assertOk();

        $onSummary = null;
        $summary->assertInertia(function (AssertableInertia $page) use (&$onSummary): void {
            $page->etc();
            $onSummary = $page->toArray()['props']['happened']['conversations'][0]['waited_seconds'];
        });

        $inQueue = null;
        $queue->assertInertia(function (AssertableInertia $page) use (&$inQueue): void {
            $page->etc();
            $inQueue = $page->toArray()['props']['conversations']['data'][0]['waited_seconds'];
        });

        $this->assertSame(42 * 60, $onSummary);
        // One definition of the number the whole contour is measured on.
        $this->assertSame($inQueue, $onSummary);
        $this->assertSame(InteractionState::New, $conversation->refresh()->state);
    }

    // ------------------------------------------------------ what awaits you

    #[Test]
    public function an_empty_slot_is_reported_as_empty_rather_than_as_no_post_at_all(): void
    {
        $this->slot(['state' => ContentItemState::Idea, 'body_markdown' => null]);

        $this->actingAs($this->member)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Planned and came to nothing, which is a different fact from
                // nothing having been planned — and the interesting one.
                ->where('awaiting.post_of_the_day.empty', true)
                ->where('awaiting.post_of_the_day.published', false)
                ->etc()
            );
    }

    #[Test]
    public function a_day_with_no_slot_reports_no_post_of_the_day(): void
    {
        $this->slot(['slot_at' => CarbonImmutable::parse(self::NOW)->addDays(3)]);

        $this->actingAs($this->member)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('awaiting.post_of_the_day', null)
                ->etc()
            );
    }

    // -------------------------------------------------------- what changed

    #[Test]
    public function a_metric_never_measured_reads_as_unmeasured_and_never_as_zero(): void
    {
        // Fourteen days of traffic and not one day of Search Console. This is
        // the ordinary shape of a project whose Google grant covers GA4 only,
        // and a zero here would say the brand lost every impression it had.
        for ($back = 1; $back <= 14; $back++) {
            ProjectState::factory()->create([
                'captured_on' => CarbonImmutable::parse(self::NOW)->subDays($back)->toDateString(),
                'brand_impressions' => null,
                'brand_clicks' => null,
                'brand_queries' => null,
                'direct_sessions' => 120,
                'post_impressions' => null,
                'post_replies' => null,
            ]);
        }

        $this->actingAs($this->member)
            ->withHeaders($this->partial('changed'))
            ->get(route('today.index'))
            ->assertOk()
            ->assertJsonPath('props.changed.0.key', 'brand_demand')
            ->assertJsonPath('props.changed.0.measured', false)
            ->assertJsonPath('props.changed.0.current', null)
            ->assertJsonPath('props.changed.0.measured_days', 0)
            ->assertJsonPath('props.changed.0.direction', null)
            // Measured, and measured as a real number rather than as a total
            // that grows with the number of days somebody happened to capture.
            ->assertJsonPath('props.changed.1.key', 'direct_traffic')
            ->assertJsonPath('props.changed.1.measured', true)
            ->assertJsonPath('props.changed.1.current', 120)
            // A channel with no impressions has no reply rate — not a rate of
            // zero, which is what the governor would throttle a project for.
            ->assertJsonPath('props.changed.2.key', 'reply_rate')
            ->assertJsonPath('props.changed.2.measured', false);
    }

    #[Test]
    public function a_measured_metric_is_a_direction_and_not_a_reading(): void
    {
        $today = CarbonImmutable::parse(self::NOW);

        // A fortnight at 100 a day, then a fortnight at 200.
        for ($back = 15; $back <= 28; $back++) {
            ProjectState::factory()->create([
                'captured_on' => $today->subDays($back)->toDateString(),
                'brand_impressions' => 100,
            ]);
        }

        for ($back = 1; $back <= 14; $back++) {
            ProjectState::factory()->create([
                'captured_on' => $today->subDays($back)->toDateString(),
                'brand_impressions' => 200,
            ]);
        }

        $this->actingAs($this->member)
            ->withHeaders($this->partial('changed'))
            ->get(route('today.index'))
            ->assertOk()
            ->assertJsonPath('props.changed.0.current', 200)
            ->assertJsonPath('props.changed.0.previous', 100)
            ->assertJsonPath('props.changed.0.direction', 'up')
            // Doubled, and reported as a fraction rather than as the reading.
            ->assertJsonPath('props.changed.0.change', 1)
            ->assertJsonPath('props.changed.0.measured_days', 14);
    }

    // ---------------------------------------------------------------- setup

    /**
     * The headers a browser sends when it comes back for a deferred prop.
     *
     * @return array<string, string>
     */
    private function partial(string $props): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'today/index',
            'X-Inertia-Partial-Data' => $props,
        ];
    }

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

    /** @param array<string, mixed> $attributes */
    private function slot(array $attributes = []): ContentItem
    {
        return ContentItem::factory()->create([
            'type' => ContentItemType::SocialPost,
            'title' => 'The post of the day',
            'social_band' => SocialBand::Question,
            'slot_at' => CarbonImmutable::parse(self::NOW)->addHours(4),
            'state' => ContentItemState::Draft,
            'body_markdown' => 'What is the one thing you wish somebody had told you?',
            ...$attributes,
        ]);
    }

    /**
     * The week's row, as the planner would have left it.
     *
     * Written directly rather than through {@see SocialPlan::record()} — the
     * governor's arithmetic is `SocialPlanTest`'s subject, and this file is
     * about what the summary does with the row once it exists.
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
