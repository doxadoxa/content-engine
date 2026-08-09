<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\EmbeddingGateway;
use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeEmbeddingGateway;
use App\Ai\FakeModelGateway;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\ContentPlanStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\SearchIntent;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\SitePage;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Steps\Planning\ScheduleCalendar;
use App\Pipelines\Steps\Planning\SelectTopics;
use App\Support\Corpus\TopicLibrary;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exit criteria 2 and 3 of phase 4: a month for a two-locale project, plan in
 * `draft` with units in `idea`; and a topic already published never reaches it.
 */
final class PlanningPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->multilingual()->create([
            'weekly_target' => 2,
        ]);
        app(CurrentProject::class)->set($this->project);

        config()->set('queue.default', 'sync');
    }

    #[Test]
    public function it_builds_a_draft_month_with_units_still_ideas(): void
    {
        foreach (['a', 'b', 'c'] as $i => $name) {
            $this->idea("topic {$name}", "cluster-{$i}");
        }

        $run = $this->plan();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $plan = ContentPlan::query()->firstOrFail();

        // A plan is a proposal until a human says otherwise (§4.2).
        $this->assertSame(ContentPlanStatus::Draft, $plan->status);
        $this->assertSame('2026-09-01', $plan->month->toDateString());

        foreach ($plan->contentItems()->get() as $unit) {
            $this->assertSame(ContentItemState::Idea, $unit->state);
            $this->assertNotNull($unit->scheduled_for);
        }
    }

    #[Test]
    public function a_two_locale_project_gets_a_row_per_locale(): void
    {
        $this->idea('topic a', 'cluster-a');

        $this->plan();

        $plan = ContentPlan::query()->firstOrFail();
        $units = $plan->contentItems()->get();

        // §2: a locale is a unit of its own, not a translation done afterwards.
        $this->assertCount(2, $units);
        $this->assertEqualsCanonicalizing(['pt-PT', 'en'], $units->pluck('locale')->all());
        $this->assertSame(1, $units->pluck('locale_group_id')->unique()->count());

        // Both are scheduled for the same day: they are one unit.
        $this->assertSame(1, $units->pluck('scheduled_for')->unique()->count());
    }

    #[Test]
    public function a_topic_already_published_never_reaches_the_plan(): void
    {
        $this->idea('window cleaning cost', 'cost');
        $this->idea('how to clean windows', 'howto');

        // The corpus already covers one of them.
        ContentItem::factory()->published()->create([
            'target_query' => 'Window Cleaning Cost',
            'locale' => $this->project->default_locale,
        ]);

        $this->plan();

        $planned = ContentPlan::query()->firstOrFail()->contentItems()->pluck('target_query')->all();

        $this->assertNotContains('window cleaning cost', $planned);
        $this->assertContains('how to clean windows', $planned);
    }

    #[Test]
    public function a_unit_being_refreshed_still_counts_as_covered(): void
    {
        $this->idea('window cleaning cost', 'cost');

        ContentItem::factory()->create([
            'target_query' => 'window cleaning cost',
            'locale' => $this->project->default_locale,
        ])->forceFill(['state' => ContentItemState::Refreshing])->save();

        $run = $this->plan();

        // Everything was already covered, so there is nothing to plan and the
        // run says so rather than producing an empty month quietly.
        $plan = ContentPlan::query()->first();

        $this->assertTrue($plan === null || $plan->contentItems()->count() === 0);
        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
    }

    #[Test]
    public function no_single_cluster_takes_over_the_month(): void
    {
        // One a week, so the month holds about four. Eight phrasings of one
        // topic and one of another must not come out as eight windows articles.
        $this->project->forceFill(['weekly_target' => 1])->save();

        foreach (range(1, 8) as $i) {
            $this->idea("cleaning windows {$i}", 'windows', 900 - $i);
        }

        $this->idea('how to clean floors', 'floors', 700);

        $this->plan();

        $planned = ContentPlan::query()->firstOrFail()
            ->contentItems()->roots()->where('locale', $this->project->default_locale)->get();

        $byCluster = $planned->countBy('cluster');

        // A month is a spread of topics, not six phrasings of the best one —
        // but the cap scales with the cadence rather than being a flat one,
        // which used to override the operator's chosen frequency entirely.
        $this->assertLessThan(
            $planned->count(),
            $byCluster['windows'] ?? 0,
            'One cluster should not be the whole month.',
        );
        $this->assertArrayHasKey('floors', $byCluster->all());
    }

    #[Test]
    public function the_chosen_cadence_is_honoured_when_the_pool_allows_it(): void
    {
        // The complaint this exists for: seven a week was set, one a week
        // arrived, because every long-tail keyword the source returned sat
        // under the same parent topic and only one per cluster was allowed.
        $this->project->forceFill(['weekly_target' => 7])->save();

        foreach (range(1, 30) as $i) {
            $this->idea("cleaning topic {$i}", 'cleaning lisbon', 900 - $i);
        }

        $this->plan();

        $planned = ContentPlan::query()->firstOrFail()
            ->contentItems()->roots()->where('locale', $this->project->default_locale)->count();

        $this->assertGreaterThanOrEqual(10, $planned);
    }

    #[Test]
    public function the_month_is_sized_by_the_projects_own_frequency(): void
    {
        // §4.3: frequency is project config, because §1 makes it the stated
        // mitigation for scaled-content abuse.
        foreach (range(1, 20) as $i) {
            $this->idea("topic {$i}", "cluster-{$i}");
        }

        $this->plan();

        $defaultLocaleUnits = ContentPlan::query()->firstOrFail()
            ->contentItems()->where('locale', $this->project->default_locale)->count();

        // 2 a week over a 30-day September ≈ 9.
        $this->assertGreaterThanOrEqual(7, $defaultLocaleUnits);
        $this->assertLessThanOrEqual(10, $defaultLocaleUnits);
    }

    #[Test]
    public function publishing_dates_are_spread_across_the_month(): void
    {
        foreach (range(1, 8) as $i) {
            $this->idea("topic {$i}", "cluster-{$i}");
        }

        $this->plan();

        $days = ContentPlan::query()->firstOrFail()
            ->contentItems()->where('locale', $this->project->default_locale)
            ->get()
            ->map(fn (ContentItem $item): int => (int) $item->scheduled_for?->day)
            ->sort()
            ->values();

        // Not all on the first: §1 names cadence as a risk, and a burst reads
        // as automated whatever the monthly average is.
        $this->assertGreaterThan(1, $days->unique()->count());
        $this->assertGreaterThan(7, $days->last() - $days->first());
    }

    #[Test]
    public function units_that_need_real_business_data_are_flagged(): void
    {
        $this->idea('window cleaning cost', 'cost')
            ->forceFill(['intent' => SearchIntent::Transactional->value])->save();
        $this->idea('how to clean windows', 'howto');

        $this->plan();

        $byQuery = ContentPlan::query()->firstOrFail()
            ->contentItems()->where('locale', $this->project->default_locale)->get()->keyBy('target_query');

        // §4.3: generation will not ask for data nobody said was needed.
        $this->assertTrue($byQuery['window cleaning cost']->needs_original_data);
        $this->assertFalse($byQuery['how to clean windows']->needs_original_data);
    }

    #[Test]
    public function the_plan_records_which_channels_a_unit_is_headed_for(): void
    {
        $this->idea('how to clean windows', 'howto');

        $this->plan();

        $unit = ContentPlan::query()->firstOrFail()
            ->contentItems()->where('locale', $this->project->default_locale)->firstOrFail();

        // Derivatives themselves are phase 8; the plan records that they are
        // coming, which is what makes a month's cost estimable.
        $this->assertSame(['linkedin', 'x'], $unit->planned_derivatives);
    }

    #[Test]
    public function a_transactional_idea_is_typed_as_a_product(): void
    {
        $idea = $this->idea('book window cleaning', 'book');
        $idea->forceFill(['intent' => SearchIntent::Transactional->value, 'type' => ContentItemType::HowTo])->save();

        $this->plan();

        $this->assertSame(
            ContentItemType::Product,
            ContentItem::query()->whereKey($idea->getKey())->firstOrFail()->type,
        );
    }

    #[Test]
    public function replanning_the_same_month_fills_the_same_draft(): void
    {
        $this->idea('topic a', 'cluster-a');

        $this->plan();
        $this->plan();

        // (project, month) is unique, so a second run must reuse the draft
        // rather than fail on a constraint an operator cannot see.
        $this->assertSame(1, ContentPlan::query()->count());
    }

    #[Test]
    public function planning_with_an_empty_pool_fails_terminally(): void
    {
        $run = $this->plan();

        $run = PipelineRun::acrossProjects()->whereKey($run->getKey())->firstOrFail();

        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame('gather_ideas', $run->failed_step_key);
        $this->assertFalse($run->error['retryable']);
    }

    #[Test]
    public function the_two_middle_steps_run_in_parallel(): void
    {
        $this->idea('topic a', 'cluster-a');

        $run = $this->plan();

        $positions = $run->steps()->get()->mapWithKeys(
            fn ($step): array => [$step->step_key => $step->position]
        )->all();

        // Selecting topics and typing units read the same pool and not each
        // other, so the graph puts them side by side.
        $this->assertSame(0, $positions['gather_ideas']);
        $this->assertSame(3, $positions[ScheduleCalendar::key()]);
        $this->assertContains($positions[SelectTopics::key()], [1, 2]);
        $this->assertContains($positions['type_and_flag'], [1, 2]);
    }

    #[Test]
    public function the_console_can_approve_a_plan(): void
    {
        $this->idea('topic a', 'cluster-a');
        $this->plan();

        /** @var PendingCommand $command */
        $command = $this->artisan('plan:approve', [
            'project' => $this->project->slug,
            'month' => '2026-09',
        ]);

        // run() explicitly, because assertSuccessful() only records the
        // expectation — a PendingCommand executes in its destructor, so held in
        // a variable it would still be unrun when the assertion below reads the
        // plan.
        $command->assertSuccessful()->run();

        $this->assertTrue(ContentPlan::query()->firstOrFail()->isApproved());
    }

    #[Test]
    public function approving_a_month_with_no_plan_fails(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('plan:approve', [
            'project' => $this->project->slug,
            'month' => '2030-01',
        ]);
        $command->assertFailed()->run();
    }

    #[Test]
    public function derivatives_are_planned_for_the_channels_this_project_has(): void
    {
        // A global config default said "linkedin, x" for every project on the
        // installation. A project that connected Telegram got posts planned for
        // two channels it does not have, and none for the one it does.
        Channel::factory()->create(['type' => ChannelType::Telegram, 'is_enabled' => true]);
        Channel::factory()->create(['type' => ChannelType::X, 'is_enabled' => true]);
        Channel::factory()->create(['type' => ChannelType::Webhook, 'is_enabled' => true]);

        $this->idea('how to clean windows', 'windows', 900);

        $this->plan();

        $planned = ContentItem::query()->roots()->whereNotNull('content_plan_id')->firstOrFail();

        // The website is where the article goes; it is not a derivative.
        $this->assertEqualsCanonicalizing(['telegram', 'x'], $planned->planned_derivatives);
    }

    #[Test]
    public function a_topic_the_site_already_covers_is_not_planned_again(): void
    {
        // The real case: the site publishes "Limpeza De Carpetes Preco" and the
        // planner is about to schedule "carpet cleaning lisbon". They share no
        // word — same subject, two languages — so a string check finds nothing
        // and the article gets written twice.
        $this->sitePage('Limpeza de carpetes: preço e o que esperar', months: 2);

        $this->embeddings()
            ->willEmbed('Limpeza de carpetes: preço e o que esperar', [1.0, 0.0, 0.0])
            ->willEmbed('carpet cleaning lisbon', [0.99, 0.14, 0.0]);

        app(TopicLibrary::class)->index($this->project);

        $this->idea('carpet cleaning lisbon', 'carpets', 500);
        $this->idea('how to clean marble floors', 'marble', 400);

        $this->plan();

        $queries = ContentItem::query()->roots()
            ->whereNotNull('content_plan_id')
            ->pluck('target_query')
            ->all();

        $this->assertNotContains('carpet cleaning lisbon', $queries);
        $this->assertContains('how to clean marble floors', $queries);
    }

    #[Test]
    public function a_topic_covered_years_ago_is_fair_game_again(): void
    {
        // Treating "ever covered" as permanent shrinks a project's plannable
        // universe every month until nothing is left. Search results move and
        // prices change; a three-year-old post is a refresh candidate, not a
        // reason never to write about something again.
        $this->sitePage('Limpeza de carpetes: preço e o que esperar', months: 40);

        $this->embeddings()
            ->willEmbed('Limpeza de carpetes: preço e o que esperar', [1.0, 0.0, 0.0])
            ->willEmbed('carpet cleaning lisbon', [0.99, 0.14, 0.0]);

        app(TopicLibrary::class)->index($this->project);

        $this->idea('carpet cleaning lisbon', 'carpets', 500);

        $this->plan();

        $this->assertContains(
            'carpet cleaning lisbon',
            ContentItem::query()->roots()->whereNotNull('content_plan_id')->pluck('target_query')->all(),
        );
    }

    #[Test]
    public function a_topic_we_have_already_written_is_not_planned_again(): void
    {
        // A draft waiting for approval is as much an article about its subject
        // as a published one. `publishedTopics()` only catches exact strings on
        // units that are already live, so "home cleaning lisbon" sailed past a
        // written "house cleaning lisbon" it measures 0.04 from.
        $written = ContentItem::factory()->create([
            'state' => ContentItemState::Approved,
            'target_query' => 'house cleaning lisbon',
        ]);

        $this->embeddings()
            ->willEmbed('house cleaning lisbon', [1.0, 0.0, 0.0])
            ->willEmbed('home cleaning lisbon', [0.9995, 0.0316, 0.0])
            ->willEmbed('how to clean marble floors', [0.0, 1.0, 0.0]);

        app(TopicLibrary::class)->rememberVector($written, 'house cleaning lisbon');

        $this->idea('home cleaning lisbon', 'home', 500);
        $this->idea('how to clean marble floors', 'marble', 400);

        $this->plan();

        $queries = ContentItem::query()->roots()->whereNotNull('content_plan_id')->pluck('target_query')->all();

        $this->assertNotContains('home cleaning lisbon', $queries);
        $this->assertContains('how to clean marble floors', $queries);
    }

    #[Test]
    public function the_same_topic_is_not_planned_twice_in_one_month(): void
    {
        // The keyword source returns the long tail around one head term, and
        // "cleaning service lisbon" and "cleaning services lisbon" arrive as
        // separate rows with separate parent topics. Planning both is two of
        // our own pages competing for one search.
        $this->embeddings()
            ->willEmbed('cleaning service lisbon', [1.0, 0.0, 0.0])
            ->willEmbed('cleaning services lisbon', [0.999, 0.045, 0.0])
            ->willEmbed('how to clean marble floors', [0.0, 1.0, 0.0]);

        $this->idea('cleaning service lisbon', 'services', 500);
        $this->idea('cleaning services lisbon', 'service-plural', 480);
        $this->idea('how to clean marble floors', 'marble', 460);

        $this->plan();

        $queries = ContentItem::query()->roots()
            ->whereNotNull('content_plan_id')
            ->pluck('target_query')
            ->all();

        $this->assertContains('cleaning service lisbon', $queries);
        $this->assertNotContains('cleaning services lisbon', $queries);
        $this->assertContains('how to clean marble floors', $queries);
    }

    #[Test]
    public function a_borderline_match_is_judged_rather_than_assumed(): void
    {
        // The band where distance alone cannot decide. On a real site "carpet
        // cleaning" against its own carpet article is 0.27 and "house cleaning"
        // against "post-renovation cleaning" is 0.31 — a hundredth apart, one a
        // duplicate and one a different service.
        $this->sitePage('Post-renovation cleaning in Lisbon', months: 2);

        $this->embeddings()
            ->willEmbed('Post-renovation cleaning in Lisbon', [1.0, 0.0, 0.0])
            // Distance 0.31: past `certain`, inside `possible`.
            ->willEmbed('house cleaning lisbon', [0.69, 0.7238, 0.0]);

        app(TopicLibrary::class)->index($this->project);

        $this->models()->willAnswerRole('utility', 'DIFFERENT');

        $this->idea('house cleaning lisbon', 'house', 500);

        $this->plan();

        $this->assertContains(
            'house cleaning lisbon',
            ContentItem::query()->roots()->whereNotNull('content_plan_id')->pluck('target_query')->all(),
        );
    }

    #[Test]
    public function a_borderline_match_the_model_calls_the_same_is_dropped(): void
    {
        $this->sitePage('Post-renovation cleaning in Lisbon', months: 2);

        $this->embeddings()
            ->willEmbed('Post-renovation cleaning in Lisbon', [1.0, 0.0, 0.0])
            ->willEmbed('cleaning after building work', [0.69, 0.7238, 0.0]);

        app(TopicLibrary::class)->index($this->project);

        $this->models()->willAnswerRole('utility', 'SAME');

        $this->idea('cleaning after building work', 'renovation', 500);

        $this->plan();

        $this->assertNotContains(
            'cleaning after building work',
            ContentItem::query()->roots()->whereNotNull('content_plan_id')->pluck('target_query')->all(),
        );
    }

    #[Test]
    public function an_undated_page_still_blocks_its_own_topic(): void
    {
        // A sitemap with no `<lastmod>` gives no way to age anything out, so it
        // is covered forever or never. Forever is the default because it is
        // what was asked for — do not write the same thing twice.
        $page = $this->sitePage('Carpet cleaning prices', months: 2);
        $page->forceFill(['published_at' => null])->save();

        $this->embeddings()
            ->willEmbed('Carpet cleaning prices', [1.0, 0.0, 0.0])
            ->willEmbed('carpet cleaning lisbon', [0.99, 0.14, 0.0]);

        app(TopicLibrary::class)->index($this->project);

        $this->idea('carpet cleaning lisbon', 'carpets', 500);

        $this->plan();

        $this->assertNotContains(
            'carpet cleaning lisbon',
            ContentItem::query()->roots()->whereNotNull('content_plan_id')->pluck('target_query')->all(),
        );
    }

    #[Test]
    public function undated_pages_can_be_told_to_stop_blocking(): void
    {
        // The escape hatch, for a site whose whole back catalogue is undated
        // and old. A setting rather than a threshold so tight the check never
        // fires at all, which is what it was before.
        config()->set('research.block_undated_pages', false);

        $page = $this->sitePage('Carpet cleaning prices', months: 2);
        $page->forceFill(['published_at' => null])->save();

        $this->embeddings()
            ->willEmbed('Carpet cleaning prices', [1.0, 0.0, 0.0])
            ->willEmbed('carpet cleaning lisbon', [0.99, 0.14, 0.0]);

        app(TopicLibrary::class)->index($this->project);

        $this->idea('carpet cleaning lisbon', 'carpets', 500);

        $this->plan();

        $this->assertContains(
            'carpet cleaning lisbon',
            ContentItem::query()->roots()->whereNotNull('content_plan_id')->pluck('target_query')->all(),
        );
    }

    #[Test]
    public function an_ideas_vector_is_computed_once_and_kept(): void
    {
        $this->sitePage('Something unrelated entirely', months: 2);

        app(TopicLibrary::class)->index($this->project);

        $this->idea('carpet cleaning lisbon', 'carpets', 500);

        $this->plan();

        $before = $this->embeddings()->callCount();

        $this->plan();

        // Re-planning a month must not pay to embed the same words again. The
        // idea already carries a vector for internal linking; this reuses it.
        $this->assertSame($before, $this->embeddings()->callCount());
    }

    #[Test]
    public function a_query_that_says_what_shape_it_wants_gets_that_shape(): void
    {
        $this->project->forceFill(['weekly_target' => 7])->save();

        $this->idea('how to clean marble floors', 'marble', 500);
        $this->idea('microfibre vs cotton cloths', 'cloths', 480);
        $this->idea('best cleaning products for tiles', 'tiles', 460);
        $this->idea('house cleaning prices lisbon', 'prices', 440);

        $this->plan();

        $types = ContentItem::query()->roots()
            ->where('locale', $this->project->default_locale)
            ->whereNotNull('content_plan_id')
            ->get()
            ->mapWithKeys(fn (ContentItem $i): array => [$i->target_query => $i->type->value]);

        $this->assertSame('how_to', $types['how to clean marble floors']);
        $this->assertSame('comparison', $types['microfibre vs cotton cloths']);
        $this->assertSame('listicle', $types['best cleaning products for tiles']);
        $this->assertSame('product', $types['house cleaning prices lisbon']);
    }

    #[Test]
    public function a_month_of_plain_topics_is_not_all_one_shape(): void
    {
        // None of these say what they want. Typed from intent alone they all
        // came out How-to, and a month of one shape reads as a template being
        // filled in — to a reader and to a search engine both.
        $this->project->forceFill(['weekly_target' => 7])->save();

        foreach (range(1, 9) as $i) {
            $this->idea("cleaning subject {$i}", "cluster-{$i}", 500 - $i);
        }

        $this->plan();

        $types = ContentItem::query()->roots()
            ->where('locale', $this->project->default_locale)
            ->whereNotNull('content_plan_id')
            ->pluck('type')
            ->map(fn ($type): string => $type->value)
            ->unique();

        $this->assertGreaterThanOrEqual(3, $types->count(), 'A month should mix shapes.');
    }

    #[Test]
    public function planning_starts_tomorrow_not_next_month(): void
    {
        Carbon::setTestNow('2026-08-15');

        $this->idea('topic a', 'cluster-a');
        app(PipelineRunner::class)->start('planning', $this->project, []);

        // Somebody who sets a project up today expects an article tomorrow, not
        // on the first of next month. Defaulting to next month meant a project
        // created on the 7th published nothing for twenty-three days.
        $this->assertSame('2026-08-01', ContentPlan::query()->firstOrFail()->month->toDateString());
        $this->assertSame(
            '2026-08-16',
            ContentItem::query()->whereNotNull('scheduled_for')->orderBy('scheduled_for')->firstOrFail()
                ->scheduled_for?->toDateString(),
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function planning_at_the_end_of_a_month_rolls_into_the_next_one(): void
    {
        Carbon::setTestNow('2026-08-29');

        $this->idea('topic a', 'cluster-a');
        app(PipelineRunner::class)->start('planning', $this->project, []);

        // Two days left is no room for a cadence. The plan rolls, and the first
        // article is still only three days out rather than a month.
        $this->assertSame('2026-09-01', ContentPlan::query()->firstOrFail()->month->toDateString());
        $this->assertSame(
            '2026-09-01',
            ContentItem::query()->whereNotNull('scheduled_for')->orderBy('scheduled_for')->firstOrFail()
                ->scheduled_for?->toDateString(),
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function a_partial_month_is_planned_at_the_same_cadence_not_crammed(): void
    {
        Carbon::setTestNow('2026-08-15');

        $this->project->forceFill(['weekly_target' => 7])->save();

        for ($i = 0; $i < 40; $i++) {
            $this->idea("topic {$i}", 'cluster-a', 500 - $i);
        }

        app(PipelineRunner::class)->start('planning', $this->project, []);

        // Sixteen days left at seven a week is sixteen articles, not a whole
        // month's thirty crushed into half a month. Roots only: a locale
        // variant is planned on the same day and would double the count.
        $scheduled = ContentItem::query()
            ->roots()
            ->where('locale', $this->project->default_locale)
            ->whereNotNull('scheduled_for')
            ->count();

        $this->assertGreaterThanOrEqual(14, $scheduled);
        $this->assertLessThanOrEqual(18, $scheduled);

        Carbon::setTestNow();
    }

    private function sitePage(string $title, int $months): SitePage
    {
        return SitePage::factory()->create([
            'title' => $title,
            'description' => null,
            'published_at' => now()->subMonths($months),
        ]);
    }

    private function models(): FakeModelGateway
    {
        /** @var FakeModelGateway $gateway */
        $gateway = app(ModelGateway::class);

        return $gateway;
    }

    private function embeddings(): FakeEmbeddingGateway
    {
        /** @var FakeEmbeddingGateway $gateway */
        $gateway = app(EmbeddingGateway::class);

        return $gateway;
    }

    private function idea(string $query, string $cluster, int $volume = 500): ContentItem
    {
        return ContentItem::factory()->create([
            'target_query' => $query,
            'cluster' => $cluster,
            'intent' => SearchIntent::Informational->value,
            'topic_volume' => $volume,
            'topic_difficulty' => 20,
            'locale' => $this->project->default_locale,
        ]);
    }

    /** @param array<string, mixed> $input */
    private function plan(array $input = ['month' => '2026-09-01']): PipelineRun
    {
        return app(PipelineRunner::class)->start('planning', $this->project, $input);
    }
}
