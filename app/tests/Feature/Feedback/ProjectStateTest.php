<?php

declare(strict_types=1);

namespace Tests\Feature\Feedback;

use App\Enums\ContentItemType;
use App\Feedback\Contracts\AnalyticsGateway;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\FakeAnalytics;
use App\Feedback\FakeSearchConsole;
use App\Feedback\ProjectStateCapture;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\ProjectState;
use App\Models\Signal;
use App\Models\SitePage;
use App\Social\Governor;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §6 of the social spec: the state of the whole project, once a day.
 *
 * The measurement layer, not the screen. What is worth asserting here is the
 * set of distinctions the row exists to keep — a day that was not measured
 * against a day where nothing happened, a channel's share against a channel's
 * count, a subject with conversation against a subject with a page — plus the
 * one join that makes the phase worth building: §4.3's governor throttling on
 * history this capture actually wrote.
 */
final class ProjectStateTest extends TestCase
{
    use RefreshDatabase;

    /** Late enough in the day that no timezone shift changes the date. */
    private const string NOW = '2026-08-20 09:00:00';

    /** Yesterday, in the project's timezone. The freshest day a sweep touches. */
    private const string YESTERDAY = '2026-08-19';

    /**
     * Three days back, which is the first day Search Console is asked about.
     *
     * {@see ProjectStateCapture::SEARCH_SETTLES_AFTER_DAYS} — a younger day is
     * left null rather than read as a zero the API has not finished counting.
     */
    private const string SETTLED = '2026-08-17';

    private Project $project;

    private FakeSearchConsole $console;

    private FakeAnalytics $analytics;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);

        config()->set('services.threads.client_id', 'threads-app-id');
        config()->set('services.threads.client_secret', 'threads-app-secret');

        $this->project = Project::factory()->create([
            'name' => 'Cleaning Point',
            'timezone' => 'Europe/Lisbon',
        ]);

        app(CurrentProject::class)->set($this->project);

        /** @var FakeSearchConsole $console */
        $console = app(SearchConsoleGateway::class);
        $this->console = $console;

        /** @var FakeAnalytics $analytics */
        $analytics = app(AnalyticsGateway::class);
        $this->analytics = $analytics;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- the row

    #[Test]
    public function a_day_is_one_row_per_project_and_a_re_run_corrects_it(): void
    {
        $other = Project::factory()->create(['name' => 'Second Project']);

        $this->analytics->willSee(self::YESTERDAY, total: 1_000, direct: 100);

        $this->capture()->capture($this->project, Carbon::parse(self::YESTERDAY));
        $this->capture()->capture($other, Carbon::parse(self::YESTERDAY));

        // One day, two projects, two rows: the unique key is on the pair.
        $this->assertSame(2, ProjectState::acrossProjects()->count());

        // The same day again, with figures the vendor has since restated —
        // which is the normal case for a window that is re-read for three
        // nights, not an error path.
        $this->analytics->willSee(self::YESTERDAY, total: 1_400, direct: 260);

        $this->capture()->capture($this->project, Carbon::parse(self::YESTERDAY));

        $this->assertSame(2, ProjectState::acrossProjects()->count());

        $row = $this->rowFor(self::YESTERDAY);

        $this->assertSame(1_400, $row->total_sessions);
        $this->assertSame(260, $row->direct_sessions);
    }

    #[Test]
    public function the_sweep_captures_complete_days_and_never_today(): void
    {
        $this->capture()->sweep($this->project);

        $days = ProjectState::query()
            ->orderBy('captured_on')
            ->pluck('captured_on')
            ->map(static fn (mixed $day): string => Carbon::parse((string) $day)->toDateString())
            ->all();

        // Today is not over. A partial day filed as a whole one is a trough in
        // the trend that never happened.
        $this->assertSame(['2026-08-17', '2026-08-18', '2026-08-19'], $days);
    }

    #[Test]
    public function an_unconnected_project_gets_a_row_of_nulls_rather_than_zeros(): void
    {
        $this->console->willBeUnconfigured();
        $this->analytics->willBeUnconfigured();

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        foreach ([
            'brand_impressions', 'brand_clicks', 'brand_queries',
            'total_sessions', 'direct_sessions', 'referral_sessions',
            'social_sessions', 'conversions',
            'followers', 'post_impressions', 'post_replies',
        ] as $column) {
            $this->assertNull($row->{$column}, "{$column} should be null when nothing was measured");
        }

        // And the two that are ours are still written, because "we published
        // nothing" is always knowable and is what §4.3's floor alerts on.
        $this->assertSame(0, $row->posts_published);
        $this->assertSame(0, $row->replies_sent);

        // A day nobody measured cannot contribute a reply rate.
        $this->assertNull($row->replyRate());
    }

    #[Test]
    public function a_gateway_that_did_not_answer_does_not_erase_what_an_earlier_run_measured(): void
    {
        $this->analytics->willSee(self::SETTLED, total: 900, social: 90);

        $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        $this->analytics->willBeUnconfigured();

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        // One transient outage must not blank a day that was measured properly.
        $this->assertSame(900, $row->total_sessions);
        $this->assertSame(90, $row->social_sessions);

        // And the provenance describes the row rather than the last attempt:
        // a flag saying "never measured" over columns that were would be the
        // half of this that misleads the digest instead of the trend.
        $this->assertTrue($row->raw['measured']['analytics']);
    }

    // --------------------------------------------------------- brand demand

    #[Test]
    public function brand_demand_matches_the_name_in_the_queries_and_keeps_the_words(): void
    {
        $this->console
            ->willBeSearchedFor(self::SETTLED, 'cleaning point', 120, 40)
            ->willBeSearchedFor(self::SETTLED, 'cleaning-point lisbon', 45, 12)
            ->willBeSearchedFor(self::SETTLED, 'cleaningpoint reviews', 20, 6)
            // Somebody looking for a subject, not for us.
            ->willBeSearchedFor(self::SETTLED, 'how to remove limescale', 4_000, 300)
            // The trap a substring match falls into: the brand is "Point".
            ->willBeSearchedFor(self::SETTLED, 'appointment cleaning service', 900, 50);

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        $this->assertSame(185, $row->brand_impressions);
        $this->assertSame(58, $row->brand_clicks);

        // §6: a number that went up without saying which words went up is not
        // actionable, so the matched queries are stored with the totals.
        $this->assertSame(
            ['cleaning point' => 120, 'cleaning-point lisbon' => 45, 'cleaningpoint reviews' => 20],
            $row->brand_queries,
        );
    }

    #[Test]
    public function a_day_search_console_has_not_settled_is_left_unmeasured(): void
    {
        $this->console->willBeSearchedFor(self::YESTERDAY, 'cleaning point', 120, 40);

        $row = $this->capture()->capture($this->project, Carbon::parse(self::YESTERDAY));

        // Not zero. Google answers nothing at all for an unsettled day, and a
        // zero here would read as a brand nobody searched for.
        $this->assertNull($row->brand_impressions);
        $this->assertNull($row->brand_queries);
        $this->assertFalse($row->raw['measured']['search_console']);
    }

    // -------------------------------------------------------- the channels

    #[Test]
    public function the_channel_split_lands_with_the_denominator_it_needs(): void
    {
        $this->analytics->willSee(
            self::SETTLED,
            total: 1_000,
            direct: 220,
            referral: 130,
            social: 200,
            socialEngaged: 150,
            socialSeconds: 9_000,
            conversions: 17,
        );

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        $this->assertSame(1_000, $row->total_sessions);
        $this->assertSame(220, $row->direct_sessions);
        $this->assertSame(130, $row->referral_sessions);
        $this->assertSame(200, $row->social_sessions);
        $this->assertSame(150, $row->social_engaged_sessions);
        $this->assertSame(9_000, $row->social_engagement_seconds);
        $this->assertSame(17, $row->conversions);

        // §6 asks for the *share*, and it is computable from the row alone
        // because the denominator is a column rather than a sum of the three
        // channels §6 happens to name — organic and paid are in the total.
        $this->assertEqualsWithDelta(0.2, $row->social_sessions / $row->total_sessions, 0.0001);
    }

    // ------------------------------------------------------------- Threads

    #[Test]
    public function threads_insights_land_on_the_row(): void
    {
        $this->connectThreads();
        $this->fakeInsights(views: 4_200, replies: 63, likes: 210, reposts: 12, quotes: 4, followers: 1_530);

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        $this->assertSame(1_530, $row->followers);
        $this->assertSame(4_200, $row->post_impressions);
        $this->assertSame(63, $row->post_replies);
        $this->assertSame(210, $row->post_likes);
        $this->assertSame(12, $row->post_reposts);
        $this->assertSame(4, $row->post_quotes);

        $this->assertEqualsWithDelta(0.015, $row->replyRate() ?? 0.0, 0.0001);
    }

    #[Test]
    public function a_metric_the_platform_left_out_is_not_a_measured_zero(): void
    {
        $this->connectThreads();

        // The shape this actually arrives in: `views` served, `replies` not.
        // `threads_manage_insights` is approved per metric, a renamed metric
        // arrives as an absent one, and a partial `data` array is the endpoint
        // answering with what it was willing to serve rather than failing.
        $this->fakeInsights(views: 4_200, likes: 210, followers: 1_530);

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        $this->assertSame(4_200, $row->post_impressions);
        $this->assertSame(210, $row->post_likes);

        // Not 0. A zero here is a reply rate of exactly nought beside four
        // thousand impressions, and §4.3 cuts a project's frequency on that
        // number — a healthy account throttled to the floor for a month
        // because one field of one response was missing.
        $this->assertNull($row->post_replies);
        $this->assertNull($row->post_reposts);
        $this->assertNull($row->post_quotes);

        // And the day does not count towards the trailing window at all, which
        // is what "not measured" has to mean by the time the governor reads it.
        $this->assertNull($row->replyRate());

        $verdict = app(Governor::class)->verdict($this->project, Carbon::parse(self::SETTLED));

        $this->assertNull($verdict->replyRate);
        $this->assertFalse($verdict->throttled);
        $this->assertSame(0, $verdict->measuredDays);
    }

    #[Test]
    public function insights_the_platform_has_not_approved_degrade_to_nulls(): void
    {
        $this->connectThreads();

        // Meta's answer for a permission this app was never reviewed for. Not
        // a credential problem, and not a bad minute: it is the permanent
        // state of an unapproved installation.
        Http::fake(['graph.threads.net/*' => Http::response([
            'error' => [
                'message' => '(#10) Application does not have permission for this action',
                'code' => 10,
            ],
        ], 400)]);

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        $this->assertNull($row->post_impressions);
        $this->assertNull($row->post_replies);
        $this->assertNull($row->followers);
        $this->assertFalse($row->raw['measured']['threads']);

        // The connection is not broken by it. A missing review is not a
        // revoked token, and telling the operator to reconnect would send them
        // to fix something that is not wrong.
        $this->assertNull(ProjectIntegration::query()
            ->where('provider', 'threads')
            ->value('failure_reason'));
    }

    // ----------------------------------------------------- entity coverage

    #[Test]
    public function coverage_names_a_subject_the_project_discusses_and_the_site_has_no_page_for(): void
    {
        SitePage::factory()->create([
            'title' => 'How to remove limescale from a kettle',
            'description' => 'A guide to limescale.',
        ]);

        Signal::factory()->count(3)->create([
            'entities' => ['hard water', 'limescale'],
            'occurred_at' => Carbon::parse(self::SETTLED.' 11:00', 'Europe/Lisbon'),
        ]);

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        $coverage = $row->entity_coverage ?? [];

        $this->assertSame(['limescale'], $coverage['covered']);

        // §6's first consequence: a subject with conversation and no page is a
        // task for the article planner, so the gap is named and weighed rather
        // than counted.
        $this->assertSame(
            [['entity' => 'hard water', 'signals' => 3, 'interactions' => 0]],
            $coverage['gaps'],
        );

        // Carried so "no gaps" can be told from "the site was never read".
        $this->assertSame(1, $coverage['pages']);
    }

    #[Test]
    public function what_the_engine_published_counts_as_covering_a_subject(): void
    {
        ContentItem::factory()->published()->create([
            'title' => 'Dealing with hard water in Lisbon',
            'entities' => ['hard water'],
            'public_url' => 'https://example.com/hard-water',
        ]);

        Signal::factory()->create([
            'entities' => ['hard water'],
            'occurred_at' => Carbon::parse(self::SETTLED.' 11:00', 'Europe/Lisbon'),
        ]);

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        $this->assertSame([], $row->entity_coverage['gaps'] ?? null);
    }

    // ------------------------------------------------- what we did ourselves

    #[Test]
    public function our_own_activity_is_counted_from_our_own_tables(): void
    {
        $this->socialPost(self::SETTLED.' 10:00');
        $this->socialPost(self::SETTLED.' 18:30');
        // The day before. Off by one here would be invisible on a dashboard
        // and would move the floor's alert of §4.3 by a post.
        $this->socialPost('2026-08-16 23:30');

        $row = $this->capture()->capture($this->project, Carbon::parse(self::SETTLED));

        $this->assertSame(2, $row->posts_published);
    }

    // ------------------------------------------------- the join with §4.3

    #[Test]
    public function the_governor_throttles_on_a_fortnight_of_captured_history(): void
    {
        $this->connectThreads();

        // Five replies per thousand impressions: half of the one percent
        // §4.3's config calls the line, sustained for a fortnight.
        $this->captureFortnight(views: 1_000, replies: 5);

        $verdict = app(Governor::class)->verdict($this->project);

        $this->assertSame(14, $verdict->measuredDays);
        $this->assertEqualsWithDelta(0.005, $verdict->replyRate ?? 0.0, 0.0001);
        $this->assertTrue($verdict->throttled);

        // Both halves of §4.3's throttle: quieter and pickier.
        $this->assertSame(2, $verdict->weeklyAllowance);
        $this->assertSame(64, $verdict->selectionFloor);
    }

    #[Test]
    public function a_healthy_fortnight_leaves_the_ceiling_where_it_was(): void
    {
        $this->connectThreads();

        $this->captureFortnight(views: 1_000, replies: 50);

        $verdict = app(Governor::class)->verdict($this->project);

        $this->assertSame(14, $verdict->measuredDays);
        $this->assertEqualsWithDelta(0.05, $verdict->replyRate ?? 0.0, 0.0001);
        $this->assertFalse($verdict->throttled);
        $this->assertSame(5, $verdict->weeklyAllowance);
        $this->assertSame(50, $verdict->selectionFloor);
    }

    #[Test]
    public function an_unmeasured_fortnight_does_not_throttle_anybody(): void
    {
        // No Threads connection at all, which is three of the four projects
        // this engine runs. The rows exist; the reply rate does not.
        for ($back = 1; $back <= 14; $back++) {
            $this->capture()->capture($this->project, Carbon::parse(self::NOW)->subDays($back));
        }

        $this->assertSame(14, ProjectState::query()->count());

        $verdict = app(Governor::class)->verdict($this->project);

        $this->assertSame(0, $verdict->measuredDays);
        $this->assertNull($verdict->replyRate);
        $this->assertFalse($verdict->throttled);
    }

    // ------------------------------------------------------------ the command

    #[Test]
    public function the_command_sweeps_every_active_project(): void
    {
        Project::factory()->create();

        $this->console('project:capture');

        // Three days each, and one row per project per day.
        $this->assertSame(6, ProjectState::acrossProjects()->count());
    }

    #[Test]
    public function the_command_can_be_pointed_at_one_day(): void
    {
        $this->console('project:capture', [
            'project' => $this->project->slug,
            '--on' => self::SETTLED,
        ]);

        $this->assertSame(1, ProjectState::query()->count());
        $this->assertSame(self::SETTLED, $this->rowFor(self::SETTLED)->captured_on->toDateString());
    }

    // ------------------------------------------------------------- helpers

    private function capture(): ProjectStateCapture
    {
        return app(ProjectStateCapture::class);
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

    private function rowFor(string $day): ProjectState
    {
        $row = ProjectState::query()->where('captured_on', $day)->first();

        $this->assertNotNull($row, "no row was captured for {$day}");

        return $row;
    }

    /** A fortnight of captured days, each read from the same scripted account. */
    private function captureFortnight(int $views, int $replies): void
    {
        $this->fakeInsights(views: $views, replies: $replies, likes: 0, reposts: 0, quotes: 0, followers: 100);

        for ($back = 1; $back <= 14; $back++) {
            $this->capture()->capture($this->project, Carbon::parse(self::NOW)->subDays($back));
        }
    }

    private function connectThreads(): void
    {
        ProjectIntegration::factory()->threads()->create();
    }

    /**
     * The two shapes the insights endpoint answers in, on the one URL it
     * serves both from — a time series for the window, a lifetime total for
     * the follower count.
     *
     * Every metric is nullable and a null one is *left out of `data`* rather
     * than sent as a zero, because that is what the endpoint does: the
     * permission is granted per metric, so a partial answer is an ordinary
     * outcome and not a malformed one. The fake used to always return all five,
     * which is the only reason the missing-metric bug could survive a suite.
     */
    private function fakeInsights(
        ?int $views = null,
        ?int $replies = null,
        ?int $likes = null,
        ?int $reposts = null,
        ?int $quotes = null,
        ?int $followers = null,
    ): void {
        Http::fake(function (ClientRequest $request) use ($views, $replies, $likes, $reposts, $quotes, $followers): mixed {
            if (! str_contains($request->url(), 'threads_insights')) {
                return Http::response([]);
            }

            if (str_contains($request->url(), 'followers_count')) {
                return $followers === null
                    ? Http::response(['error' => ['message' => 'no', 'code' => 10]], 400)
                    : Http::response(['data' => [[
                        'name' => 'followers_count',
                        'period' => 'lifetime',
                        'total_value' => ['value' => $followers],
                    ]]]);
            }

            $data = [];

            if ($views !== null) {
                $data[] = ['name' => 'views', 'period' => 'day', 'values' => [
                    ['value' => $views, 'end_time' => '2026-08-18T07:00:00+0000'],
                ]];
            }

            foreach (['likes' => $likes, 'replies' => $replies, 'reposts' => $reposts, 'quotes' => $quotes] as $metric => $value) {
                if ($value !== null) {
                    $data[] = ['name' => $metric, 'period' => 'day', 'total_value' => ['value' => $value]];
                }
            }

            return Http::response(['data' => $data]);
        });
    }

    private function socialPost(string $at): ContentItem
    {
        return ContentItem::factory()->published()->create([
            'type' => ContentItemType::SocialPost,
            'published_at' => CarbonImmutable::parse($at, 'Europe/Lisbon')->utc(),
        ]);
    }
}
