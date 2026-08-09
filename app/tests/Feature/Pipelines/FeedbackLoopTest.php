<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\PipelineRunStatus;
use App\Feedback\Contracts\AnalyticsGateway;
use App\Feedback\Contracts\CitationChecker;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\FakeAnalytics;
use App\Feedback\FakeCitationChecker;
use App\Feedback\FakeSearchConsole;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\User;
use App\Onboarding\BriefOnboarding;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Steps\Feedback\CheckCitations;
use App\Pipelines\Steps\Feedback\DetectDegradation;
use App\Pipelines\Steps\Feedback\FetchEngagement;
use App\Pipelines\Steps\Feedback\FetchPerformance;
use App\Pipelines\Steps\Feedback\QueueRefresh;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9, and the Phase 2 exit criteria of the spec: next month's plan is
 * built on what actually happened, and an article that decayed went through a
 * refresh.
 */
final class FeedbackLoopTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeSearchConsole $console;

    private FakeAnalytics $analytics;

    private FakeCitationChecker $citations;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06');

        $this->project = Project::factory()->create(['weekly_target' => 1]);
        app(CurrentProject::class)->set($this->project);

        BrandBrief::revise($this->project, ['tone' => 'Plain.']);

        /** @var FakeSearchConsole $console */
        $console = app(SearchConsoleGateway::class);
        $this->console = $console;

        /** @var FakeAnalytics $analytics */
        $analytics = app(AnalyticsGateway::class);
        $this->analytics = $analytics;

        /** @var FakeCitationChecker $citations */
        $citations = app(CitationChecker::class);
        $this->citations = $citations;

        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;

        config()->set('queue.default', 'sync');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- gathering

    #[Test]
    public function performance_is_matched_to_units_by_public_url(): void
    {
        $unit = $this->live('how-to-clean-windows', 'https://site.test/windows');

        $this->console->willReport('https://site.test/windows', '2026-08-01', 400, 20, 6.2);

        $run = $this->feedback();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $metric = $unit->metrics()->firstOrFail();

        // `public_url` is the only thing joining the two halves of the system,
        // which is why the receiver had to answer with one back in phase 6.
        $this->assertSame(400, $metric->impressions);
        $this->assertSame(20, $metric->clicks);
        $this->assertSame(6.2, $metric->position());
        $this->assertTrue($metric->indexed);
    }

    #[Test]
    public function a_project_with_no_search_console_skips_rather_than_failing(): void
    {
        $this->live('a-unit', 'https://site.test/a');

        $console = app(SearchConsoleGateway::class);
        $this->assertInstanceOf(FakeSearchConsole::class, $console);
        $console->willBeUnconfigured();

        $run = $this->feedback();

        // Not connecting Google is a choice, not a fault. A nightly run that
        // fails for every unconnected project teaches an operator to stop
        // reading failures — which is how a real one gets missed.
        $this->assertNotSame(PipelineRunStatus::Failed, $run->refresh()->status);
    }

    #[Test]
    public function refetching_a_day_corrects_it_rather_than_doubling_it(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        $this->console->willReport('https://site.test/a', '2026-08-01', 100, 5);
        $this->feedback();

        // Search Console restates recent days as they settle.
        $console = app(SearchConsoleGateway::class);
        $this->assertInstanceOf(FakeSearchConsole::class, $console);
        $this->console = $console;
        $this->console->willReport('https://site.test/a', '2026-08-01', 180, 9);
        $this->feedback();

        $this->assertSame(1, $unit->metrics()->count());
        $this->assertSame(180, $unit->metrics()->firstOrFail()->impressions);
    }

    #[Test]
    public function a_project_with_nothing_published_skips_rather_than_fails(): void
    {
        ContentItem::factory()->draft()->create(['slug' => 'not-out-yet']);

        $run = $this->feedback();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame('skipped', $run->steps()->where('step_key', 'fetch_performance')->firstOrFail()->status->value);
    }

    // ------------------------------------------------- exit criterion 2: refresh

    #[Test]
    public function an_article_that_decayed_goes_through_a_refresh(): void
    {
        $unit = $this->live('decaying-article', 'https://site.test/decaying');

        // Fine in July, dying by August.
        $this->console->willDecay('https://site.test/decaying', '2026-07-10', 20, 300, 20);

        $this->feedback();

        $unit->refresh();

        $this->assertSame(ContentItemState::Refreshing, $unit->state);
        $this->assertNotNull($unit->refresh_due_at);
        $this->assertStringContainsString('impressions fell', (string) $unit->refresh_reason);
    }

    #[Test]
    public function a_healthy_article_is_left_alone(): void
    {
        $unit = $this->live('healthy-article', 'https://site.test/healthy');

        for ($day = 1; $day <= 20; $day++) {
            $this->console->willReport(
                'https://site.test/healthy',
                Carbon::parse('2026-07-15')->addDays($day)->toDateString(),
                300,
                15,
            );
        }

        $this->feedback();

        $this->assertSame(ContentItemState::Published, $unit->refresh()->state);
        $this->assertNull($unit->refresh_due_at);
    }

    #[Test]
    public function a_brand_new_article_is_never_called_degraded(): void
    {
        $unit = $this->live('brand-new', 'https://site.test/new');

        // Four days of data, sagging. Too new to have a trend — and refreshing
        // on this would mean rewriting everything a week after it goes out.
        $this->console->willDecay('https://site.test/new', '2026-08-01', 4, 200, 10);

        $this->feedback();

        $this->assertSame(ContentItemState::Published, $unit->refresh()->state);
    }

    #[Test]
    public function an_article_nobody_ever_found_is_not_a_refresh_problem(): void
    {
        $unit = $this->live('never-found', 'https://site.test/never');

        for ($day = 0; $day < 20; $day++) {
            $this->console->willReport(
                'https://site.test/never',
                Carbon::parse('2026-07-15')->addDays($day)->toDateString(),
                1,
                0,
            );
        }

        $this->feedback();

        // Rewriting an article nobody ever found does not make anybody find it.
        // That is a planning signal, not a refresh one.
        $this->assertSame(ContentItemState::Published, $unit->refresh()->state);
    }

    #[Test]
    public function a_refresh_lands_back_in_draft_for_a_human(): void
    {
        $unit = $this->live('decaying-article', 'https://site.test/decaying', [
            'entities' => [],
            'needs_original_data' => false,
        ]);

        $this->console->willDecay('https://site.test/decaying', '2026-07-10', 20, 300, 20);
        $this->feedback();

        $this->assertSame(ContentItemState::Refreshing, $unit->refresh()->state);

        // The rewrite is the generation pipeline — the same one that wrote it.
        $this->models
            ->willAnswerRole('outline', "One\nTwo")
            ->willAnswerRole('draft', "## One\n\nA rewritten body that is quite a lot longer than the original so that it stands on its own.\n\nSummary: rewritten.")
            ->willAnswerRole('factcheck', 'PASS')
            ->willAnswerRole('utility', "Q: A?\nA: B.");

        app(PipelineRunner::class)->start('generation', $this->project, [], $unit->getKey());

        // §1 makes approve-by-default the mitigation for scaled content, and a
        // rewrite is new text — so it goes back in front of a human.
        $this->assertSame(ContentItemState::Draft, $unit->refresh()->state);
    }

    // ---------------------------------------- exit criterion 1: planning signals

    #[Test]
    public function next_months_plan_is_built_on_what_actually_happened(): void
    {
        // Two clusters, both published, one of which earned clicks.
        $winner = $this->live('winner', 'https://site.test/winner', ['cluster' => 'windows']);
        $loser = $this->live('loser', 'https://site.test/loser', ['cluster' => 'floors']);

        ContentMetric::factory()->for($winner, 'contentItem')->create(['clicks' => 500, 'impressions' => 5_000]);
        ContentMetric::factory()->for($loser, 'contentItem')->create(['clicks' => 1, 'impressions' => 5_000]);

        // Two ideas of equal raw opportunity, one in each cluster.
        foreach ([['from-winner', 'windows'], ['from-loser', 'floors']] as [$slug, $cluster]) {
            ContentItem::factory()->create([
                'slug' => $slug,
                'target_query' => $slug,
                'cluster' => $cluster,
                'topic_volume' => 1_000,
                'topic_difficulty' => 20,
            ]);
        }

        app(PipelineRunner::class)->start('planning', $this->project, ['month' => '2026-09-01']);

        $planned = ContentPlan::query()->firstOrFail()
            ->contentItems()
            ->whereIn('slug', ['from-winner', 'from-loser'])
            ->orderBy('scheduled_for')
            ->pluck('slug')
            ->all();

        // §9.1: what worked gets planned more of. Both ideas have identical raw
        // opportunity, so the only thing that can order them is what the
        // published articles in their clusters actually earned.
        $this->assertSame(['from-winner', 'from-loser'], $planned);
    }

    #[Test]
    public function a_project_with_no_history_can_still_plan(): void
    {
        ContentItem::factory()->create([
            'slug' => 'an-idea',
            'target_query' => 'an idea',
            'cluster' => 'something',
            'topic_volume' => 900,
        ]);

        app(PipelineRunner::class)->start('planning', $this->project, ['month' => '2026-09-01']);

        // Every multiplier is 1 when nothing has been measured, so a new
        // project plans on opportunity alone rather than failing.
        $this->assertSame(1, ContentPlan::query()->firstOrFail()->contentItems()->count());
    }

    // ------------------------------------------------------- citability (§9.3)

    #[Test]
    public function citability_is_measured_against_the_target_query(): void
    {
        $unit = $this->live('cited-article', 'https://site.test/cited', ['topic_volume' => 5_000]);

        $this->console->willReport('https://site.test/cited', '2026-08-01', 100, 5);
        $this->citations->willFind('cited article', ['chatgpt' => true, 'perplexity' => false]);

        $this->feedback();

        $unit->refresh();

        // The metric the whole GEO layer exists to move — until this, it was
        // being optimised blind.
        $this->assertTrue($unit->citations['chatgpt']);
        $this->assertFalse($unit->citations['perplexity']);
        $this->assertNotNull($unit->citations_checked_at);
    }

    #[Test]
    public function the_citation_check_has_a_budget(): void
    {
        foreach (range(1, 5) as $i) {
            $this->live("unit-{$i}", "https://site.test/{$i}", ['topic_volume' => $i * 100]);
            $this->console->willReport("https://site.test/{$i}", '2026-08-01', 100, 5);
        }

        $this->feedback(['citation_budget' => 2]);

        // A model call each, so the budget is a limit rather than a surprise —
        // and the units with the most to lose go first.
        $this->assertSame(2, ContentItem::query()->whereNotNull('citations_checked_at')->count());
        $this->assertNotNull(ContentItem::query()->where('slug', 'unit-5')->firstOrFail()->citations_checked_at);
    }

    #[Test]
    public function the_two_branches_run_in_parallel(): void
    {
        $this->live('a-unit', 'https://site.test/a');
        $this->console->willReport('https://site.test/a', '2026-08-01', 100, 5);

        $run = $this->feedback();

        $positions = $run->steps()->get()->mapWithKeys(
            fn ($step): array => [$step->step_key => $step->position]
        )->all();

        // Asserted as an ordering rather than as literal indices: the ordering
        // is what the DAG promises, and a test pinned to index 3 fails the next
        // time a step is added without anything having gone wrong.
        $this->assertLessThan(
            $positions[DetectDegradation::key()],
            $positions[FetchPerformance::key()],
            'Degradation has to be decided after the search data is in.',
        );
        $this->assertLessThan(
            $positions[DetectDegradation::key()],
            $positions[FetchEngagement::key()],
            'Degradation has to be decided after the engagement data is in.',
        );
        $this->assertLessThan(
            $positions[QueueRefresh::key()],
            $positions[DetectDegradation::key()],
            'Nothing is queued for refresh before it has been judged decayed.',
        );

        // Citations answer a different question and wait on nothing that
        // degradation waits on.
        $this->assertLessThan(
            $positions[QueueRefresh::key()],
            $positions[CheckCitations::key()],
        );
    }

    #[Test]
    public function engagement_lands_on_the_same_daily_row_as_the_search_data(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        $this->console->willReport('https://site.test/a', '2026-08-01', 100, 5);
        $this->analytics->willReport('https://site.test/a', '2026-08-01', 40, 28, 1200);

        $this->feedback();

        // One row per unit per day is what makes a trend comparable. Two tables
        // would mean joining them every time anybody asked about a day.
        $this->assertSame(1, $unit->metrics()->count());

        $reading = $unit->metrics()->firstOrFail();

        $this->assertSame(100, $reading->impressions);
        $this->assertSame(40, $reading->sessions);
        $this->assertSame(28, $reading->engaged_sessions);
    }

    #[Test]
    public function one_article_reached_by_two_paths_is_summed_not_overwritten(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        // GA4 answers one row per path, and a campaign parameter makes a second
        // path for the same article. Writing them one after another would leave
        // the last one standing rather than the total.
        $this->analytics->willReport('https://site.test/a', '2026-08-01', 40, 28, 1200);
        $this->analytics->willReport('https://site.test/a', '2026-08-01', 10, 6, 300);

        $this->feedback();

        $reading = $unit->metrics()->firstOrFail();

        $this->assertSame(50, $reading->sessions);
        $this->assertSame(34, $reading->engaged_sessions);
        $this->assertSame(1500, $reading->engagement_seconds);
    }

    #[Test]
    public function a_project_with_no_analytics_still_gets_its_search_data(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        $this->analytics->willBeUnconfigured();
        $this->console->willReport('https://site.test/a', '2026-08-01', 100, 5);

        $run = $this->feedback();

        $this->assertNotSame(PipelineRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(100, $unit->metrics()->firstOrFail()->impressions);

        // Null, not zero. "We do not know" and "nobody came" are different
        // facts, and the degradation check reads them differently.
        $this->assertNull($unit->metrics()->firstOrFail()->sessions);
    }

    #[Test]
    public function an_article_people_stopped_reading_is_queued_even_though_search_looks_fine(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        // Impressions flat across the whole window: from search data alone this
        // article is perfectly healthy.
        for ($day = 0; $day < 14; $day++) {
            $this->console->willReport(
                'https://site.test/a',
                Carbon::parse('2026-07-24')->addDays($day)->toDateString(),
                200,
                10,
            );
        }

        // And yet the people arriving stopped staying.
        $this->analytics->willDisengage('https://site.test/a', '2026-07-24', 14, 60, 0.8, 0.2);

        $this->feedback();

        $unit->refresh();

        // The whole reason Analytics was connected: a page whose impressions
        // hold while engagement collapses has stopped answering its own
        // question, and search data cannot see that.
        $this->assertNotNull($unit->refresh_due_at);
        $this->assertStringContainsString('stopped engaging', (string) $unit->refresh_reason);
    }

    #[Test]
    public function analytics_alone_does_not_make_articles_look_unfound(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        // No Search Console at all, so every row this run writes has zero
        // impressions on it — not because the article was never shown, but
        // because nobody asked.
        $this->console->willBeUnconfigured();

        for ($day = 0; $day < 14; $day++) {
            $this->analytics->willReport(
                'https://site.test/a',
                Carbon::parse('2026-07-24')->addDays($day)->toDateString(),
                60,
                48,
                2400,
            );
        }

        $this->feedback();

        // Reading those zeroes as decay would queue a refresh for every article
        // on every project that connected only Analytics.
        $this->assertNull($unit->refresh()->refresh_due_at);
    }

    #[Test]
    public function an_article_nobody_ever_engaged_with_is_not_called_decayed(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        for ($day = 0; $day < 14; $day++) {
            $this->console->willReport(
                'https://site.test/a',
                Carbon::parse('2026-07-24')->addDays($day)->toDateString(),
                200,
                10,
            );
            // Consistently skimmed, start to finish.
            $this->analytics->willReport(
                'https://site.test/a',
                Carbon::parse('2026-07-24')->addDays($day)->toDateString(),
                60,
                12,
                400,
            );
        }

        $this->feedback();

        // It is not decaying — it was never engaging. That is a planning
        // decision, and rewriting it here would be the engine churning on
        // something no signal says has changed.
        $this->assertNull($unit->refresh()->refresh_due_at);
    }

    #[Test]
    public function an_article_stuck_on_page_two_is_queued_for_a_refresh(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        // Ranking, earning impressions, and below where anybody clicks. This is
        // the cheapest win a project has: already written, already indexed, a
        // few improvements from page one.
        for ($day = 0; $day < 14; $day++) {
            $this->console->willReport(
                'https://site.test/a',
                Carbon::parse('2026-07-24')->addDays($day)->toDateString(),
                impressions: 40,
                clicks: 0,
                position: 13.0,
            );
        }

        $this->feedback();

        $unit->refresh();

        $this->assertNotNull($unit->refresh_due_at);
        $this->assertStringContainsString('page two', (string) $unit->refresh_reason);
    }

    #[Test]
    public function an_article_on_page_one_is_left_alone(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        for ($day = 0; $day < 14; $day++) {
            $this->console->willReport(
                'https://site.test/a',
                Carbon::parse('2026-07-24')->addDays($day)->toDateString(),
                impressions: 40,
                clicks: 6,
                position: 4.0,
            );
        }

        $this->feedback();

        // Rewriting something that already ranks is how a project loses a
        // position it worked for.
        $this->assertNull($unit->refresh()->refresh_due_at);
    }

    #[Test]
    public function a_position_nobody_saw_is_not_an_opportunity(): void
    {
        $unit = $this->live('a-unit', 'https://site.test/a');

        // Page two, but on three impressions a fortnight. That is a stray
        // appearance, not a ranking worth spending an article on.
        for ($day = 0; $day < 14; $day++) {
            $this->console->willReport(
                'https://site.test/a',
                Carbon::parse('2026-07-24')->addDays($day)->toDateString(),
                impressions: 2,
                clicks: 0,
                position: 13.0,
            );
        }

        $this->feedback();

        $this->assertNull($unit->refresh()->refresh_due_at);
    }

    // ------------------------------------------------- exit criterion 4: pull API

    #[Test]
    public function the_pull_api_serves_the_corpus_with_versioning(): void
    {
        $token = 'a-pull-token';

        Channel::factory()->create([
            'type' => ChannelType::PullApi,
            'name' => 'Static site',
            'secret' => $token,
        ]);

        $first = $this->live('first-article', 'https://site.test/first');
        Carbon::setTestNow('2026-08-07');
        $second = $this->live('second-article', 'https://site.test/second');

        $response = $this->withToken($token)->getJson('/api/content?per_page=1');

        $response->assertOk()
            ->assertJsonPath('contract', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('data.0.slug', 'first-article');

        // The opaque composite cursor includes the id as a tie-breaker, so
        // rows written in the same database timestamp cannot disappear at a
        // page boundary.
        $next = $response->json('meta.next_cursor');

        $this->withToken($token)->getJson('/api/content?cursor='.urlencode((string) $next))
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'second-article')
            ->assertJsonPath('meta.has_more', false);

        $this->assertNotNull($first->public_url);
        $this->assertNotNull($second->public_url);
    }

    #[Test]
    public function the_pull_cursor_never_skips_rows_with_the_same_timestamp(): void
    {
        Channel::factory()->create([
            'type' => ChannelType::PullApi,
            'name' => 'Timestamp tie',
            'secret' => 'tie-token',
        ]);

        $units = collect([
            $this->live('tie-one', 'https://site.test/one'),
            $this->live('tie-two', 'https://site.test/two'),
            $this->live('tie-three', 'https://site.test/three'),
        ]);

        ContentItem::query()->whereKey($units->pluck('id'))->update([
            'updated_at' => Carbon::parse('2026-08-07 10:00:00'),
        ]);

        $cursor = null;
        $slugs = [];

        do {
            $query = $cursor === null ? '' : '&cursor='.urlencode($cursor);
            $response = $this->withToken('tie-token')->getJson('/api/content?per_page=1'.$query)->assertOk();
            $slugs[] = $response->json('data.0.slug');
            $cursor = $response->json('meta.next_cursor');
        } while ($response->json('meta.has_more'));

        $this->assertEqualsCanonicalizing(['tie-one', 'tie-two', 'tie-three'], $slugs);
        $this->assertCount(3, array_unique($slugs));
    }

    #[Test]
    public function the_pull_api_rejects_unbounded_or_ambiguous_pagination_inputs(): void
    {
        Channel::factory()->create([
            'type' => ChannelType::PullApi,
            'name' => 'Validated pull',
            'secret' => 'validation-token',
        ]);

        $this->withToken('validation-token')->getJson('/api/content?per_page=nope')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->withToken('validation-token')->getJson('/api/content?cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');

        $this->withToken('validation-token')->getJson('/api/content?since=2026-08-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('since');
    }

    #[Test]
    public function pull_tokens_are_indexed_without_storing_a_reusable_lookup_value(): void
    {
        $channel = Channel::factory()->create([
            'type' => ChannelType::PullApi,
            'name' => 'Hashed pull',
            'secret' => 'raw-secret-token',
        ]);

        $this->assertNotNull($channel->token_hash);
        $this->assertNotSame('raw-secret-token', $channel->token_hash);
        $this->assertSame(64, strlen($channel->token_hash));
        $this->withToken('raw-secret-token')->getJson('/api/content')->assertOk();
    }

    #[Test]
    public function the_pull_api_refuses_an_unknown_token(): void
    {
        $this->live('an-article', 'https://site.test/a');

        $this->getJson('/api/content')->assertUnauthorized();
        $this->withToken('not-a-real-token')->getJson('/api/content')->assertUnauthorized();
    }

    #[Test]
    public function the_pull_api_serves_only_the_tokens_own_project(): void
    {
        Channel::factory()->create([
            'type' => ChannelType::PullApi,
            'name' => 'Mine',
            'secret' => 'my-token',
        ]);

        $this->live('mine', 'https://site.test/mine');

        $other = Project::factory()->create();
        app(CurrentProject::class)->run($other, function (): void {
            ContentItem::factory()->published()->create(['slug' => 'theirs', 'public_url' => 'https://x.test/t']);
        });

        // The token chooses the tenant, because there is no session here.
        $this->withToken('my-token')->getJson('/api/content')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'mine');
    }

    #[Test]
    public function a_disabled_pull_channel_stops_working(): void
    {
        $channel = Channel::factory()->create([
            'type' => ChannelType::PullApi,
            'name' => 'Retired',
            'secret' => 'retired-token',
        ]);

        $channel->forceFill(['is_enabled' => false])->save();

        // A pull consumer is a channel like any other, so turning it off in the
        // panel is what revokes it.
        $this->withToken('retired-token')->getJson('/api/content')->assertUnauthorized();
    }

    // ---------------------------------------------------- the screen (§9.6)

    #[Test]
    public function the_feedback_screen_shows_the_refresh_queue_and_citability(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach($this->project, ['role' => 'owner']);

        $decayed = $this->live('decaying-article', 'https://site.test/decaying', ['topic_volume' => 9_000]);
        $this->console->willDecay('https://site.test/decaying', '2026-07-10', 20, 300, 20);
        $this->citations->willFind('decaying article', ['chatgpt' => true]);

        $this->feedback();

        $this->actingAs($operator)
            ->get(route('feedback.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('feedback/index')
                ->where('summary.refreshing', 1)
                ->where('summary.cited', 1)
                ->where('refresh_queue.0.id', $decayed->getKey())
                ->where('units.data.0.refresh_due', true)
                ->where('units.data.0.cited', ['chatgpt'])
                ->has('trend')
            );

        $this->assertSame(ContentItemState::Refreshing, $decayed->refresh()->state);
    }

    // ------------------------------------------- exit criterion 3: onboarding

    #[Test]
    public function the_onboarding_agent_compiles_a_brief_from_a_conversation(): void
    {
        $fresh = Project::factory()->create(['slug' => 'a-new-project']);

        $this->models->willAnswerRole('draft', implode("\n", [
            'POSITIONING: A Lisbon locksmith who turns up when they say they will.',
            'AUDIENCE: People locked out, and landlords with several flats.',
            'TONE: Calm and specific. No urgency language.',
            'VISUAL: Real doors, real vans, daylight.',
            'FORBIDDEN: scare tactics | competitor bashing',
            'LIKED: We can be there in forty minutes and it costs €80.',
            'DISLIKED: Locked out?! Don\'t panic!!!',
            'COMPETITORS: chaves24.pt | locksmith-lisboa.pt',
        ]));

        $brief = app(CurrentProject::class)->run(
            $fresh,
            fn () => app(BriefOnboarding::class)->compile($fresh, [
                'business' => 'We are a locksmith in Lisbon.',
                'voice' => 'Calm. We hate the panic thing everyone else does.',
                'avoid' => 'No scare tactics.',
            ]),
        );

        // Comparable in shape to the hand-written ones — same fields, same
        // compile, same version history.
        $this->assertSame(1, $brief->version);
        $this->assertTrue($brief->is_active);
        $this->assertStringContainsString('locksmith', $brief->positioning);
        $this->assertSame(['scare tactics', 'competitor bashing'], $brief->forbidden_topics);
        $this->assertSame(['chaves24.pt', 'locksmith-lisboa.pt'], $brief->competitors);
        $this->assertStringContainsString('## Tone of voice', $brief->compileToPrompt());
    }

    #[Test]
    public function the_onboarding_agent_is_shown_the_briefs_we_already_have(): void
    {
        $fresh = Project::factory()->create(['slug' => 'another-new-project']);

        app(CurrentProject::class)->run(
            $fresh,
            fn () => app(BriefOnboarding::class)->compile($fresh, ['business' => 'Something.']),
        );

        // §3.1: doing this phase last is what buys the few-shot examples.
        $this->assertStringContainsString(
            'Briefs of this quality',
            $this->models->lastRequest()->instructions,
        );
    }

    #[Test]
    public function a_second_onboarding_becomes_a_new_version(): void
    {
        $fresh = Project::factory()->create(['slug' => 'revised-project']);

        app(CurrentProject::class)->run($fresh, function () use ($fresh): void {
            app(BriefOnboarding::class)->compile($fresh, ['business' => 'First pass.']);
            app(BriefOnboarding::class)->compile($fresh, ['business' => 'Second pass.']);
        });

        $this->assertSame(2, $fresh->brandBriefs()->count());
        $this->assertSame(2, BrandBrief::activeFor($fresh)?->version);
    }

    /** @param array<string, mixed> $attributes */
    private function live(string $slug, string $url, array $attributes = []): ContentItem
    {
        return ContentItem::factory()->published()->create([
            'slug' => $slug,
            'public_url' => $url,
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'target_query' => str_replace('-', ' ', $slug),
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $input */
    private function feedback(array $input = []): PipelineRun
    {
        return app(PipelineRunner::class)->start('feedback', $this->project, $input);
    }
}
