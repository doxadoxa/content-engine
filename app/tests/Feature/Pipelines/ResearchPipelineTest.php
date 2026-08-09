<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\ContentItemState;
use App\Enums\PipelineRunStatus;
use App\Enums\SearchIntent;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Definitions\ResearchPipeline;
use App\Pipelines\Steps\Research\StoreIdeas;
use App\Research\Contracts\KeywordSource;
use App\Research\FakeKeywordSource;
use App\Research\KeywordIdea;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Exit criterion 1 of phase 4: research on a fake source produces a
 * reproducible idea pool, and a repeat in the same week breeds no duplicates.
 */
final class ResearchPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeKeywordSource $keywords;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'research_seeds' => ['window cleaning'],
            'market' => 'pt',
        ]);
        app(CurrentProject::class)->set($this->project);

        /** @var FakeKeywordSource $source */
        $source = app(KeywordSource::class);
        $this->keywords = $source;

        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;

        config()->set('queue.default', 'sync');
    }

    #[Test]
    public function it_turns_keywords_into_ideas(): void
    {
        $run = $this->research();

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $ideas = ContentItem::query()->get();

        $this->assertGreaterThan(0, $ideas->count());

        foreach ($ideas as $idea) {
            $this->assertSame(ContentItemState::Idea, $idea->state);
            $this->assertNull($idea->content_plan_id);
            $this->assertNotNull($idea->target_query);
            $this->assertNotNull($idea->topic_volume);
            $this->assertNotNull($idea->intent);
            $this->assertNotNull($idea->cluster);
        }
    }

    #[Test]
    public function it_asks_the_source_about_the_projects_own_market(): void
    {
        $this->research();

        // Volume for one phrase in Portugal and in the US are different numbers
        // about different businesses; asking for the wrong one silently plans
        // the wrong month.
        $this->assertSame('pt', $this->keywords->asked()[0]['market']);
        $this->assertSame('window cleaning', $this->keywords->asked()[0]['seed']);
    }

    #[Test]
    public function it_asks_in_the_language_the_project_publishes_in(): void
    {
        $this->research();

        // This project sells in Portugal and writes in English, which is the
        // configuration that broke before: the market alone implies Portuguese,
        // and a pool built from Portuguese volumes plans a month of articles
        // nobody on the site can read.
        //
        // Asserted here rather than only in the adapter's own test, because a
        // value carried and a value *passed* are different claims. A locale the
        // step never sends is a locale nothing reads.
        $this->assertSame('en', $this->project->default_locale);
        $this->assertSame('en', $this->keywords->asked()[0]['language']);
    }

    #[Test]
    public function a_project_writing_in_the_markets_own_language_asks_in_that(): void
    {
        $this->project->forceFill(['default_locale' => 'pt-PT'])->save();

        $this->research();

        $this->assertSame('pt-PT', $this->keywords->asked()[0]['language']);
    }

    #[Test]
    public function the_pool_is_reproducible(): void
    {
        $this->research();
        $first = ContentItem::query()->orderBy('slug')->pluck('target_query')->all();

        ContentItem::query()->delete();

        $this->research();
        $second = ContentItem::query()->orderBy('slug')->pluck('target_query')->all();

        // Exit criterion 1 says "reproducible", which is only testable if the
        // source and the ordering are both deterministic.
        $this->assertSame($first, $second);
    }

    #[Test]
    public function running_it_twice_breeds_no_duplicates(): void
    {
        $this->research();
        $after = ContentItem::query()->count();

        $this->research();

        $this->assertSame($after, ContentItem::query()->count());
    }

    #[Test]
    public function a_topic_already_in_the_corpus_is_skipped_however_it_is_spelled(): void
    {
        ContentItem::factory()->create(['target_query' => 'How To  Window Cleaning']);

        $this->research();

        // Compared as topics rather than as strings: same article, different
        // capitalisation and spacing.
        $matching = ContentItem::query()
            ->get()
            ->filter(fn (ContentItem $item): bool => StoreIdeas::normalise((string) $item->target_query) === 'how to window cleaning');

        $this->assertCount(1, $matching);
    }

    #[Test]
    public function keywords_below_the_floor_are_left_out(): void
    {
        // Three that pass, because a pool that cannot fill a month is itself a
        // failure now — this test is about the floor, not about pool size.
        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('worth writing', volume: 900, difficulty: 20),
            new KeywordIdea('also worth writing', volume: 800, difficulty: 25),
            new KeywordIdea('worth writing too', volume: 700, difficulty: 30),
            new KeywordIdea('too obscure', volume: 5, difficulty: 1),
            new KeywordIdea('too competitive', volume: 90_000, difficulty: 95),
        ]);

        $this->research();

        $queries = ContentItem::query()->pluck('target_query')->all();

        $this->assertContains('worth writing', $queries);
        $this->assertNotContains('too obscure', $queries);
        $this->assertNotContains('too competitive', $queries);
    }

    #[Test]
    public function a_pool_too_thin_to_plan_from_fails_and_says_why(): void
    {
        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('the only one', volume: 900, difficulty: 20),
        ]);

        $run = $this->research();

        // Succeeding here would plan a month of one article and leave the
        // operator staring at a dashboard that looks broken, with the cause
        // four steps upstream where they will never look.
        $this->assertSame(PipelineRunStatus::Failed, $run->refresh()->status);

        $message = (string) ($run->error['message'] ?? '');

        // The message has to carry the fix, not just the fact: the usual cause
        // is seeds phrased as marketing copy against a source that matches by
        // containment.
        $this->assertStringContainsString('seeds', $message);
        $this->assertStringContainsString('containment', $message);
    }

    #[Test]
    public function a_thin_pool_is_allowed_when_the_floor_is_lowered(): void
    {
        // A genuinely tiny niche is not a misconfiguration, so the threshold
        // has to be movable rather than a number baked into the step.
        config()->set('research.minimum_pool', 1);

        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('the only one', volume: 900, difficulty: 20),
        ]);

        $run = $this->research();

        $this->assertNotSame(PipelineRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(['the only one'], ContentItem::query()->pluck('target_query')->all());
    }

    #[Test]
    public function a_local_project_can_lower_the_floor_the_default_sets_for_a_country(): void
    {
        // The installation default is chosen for a national market. A cleaning
        // business in one city has a best keyword of 70 a month and everything
        // else at 30 — a global floor of 50 passes one of sixteen real
        // keywords and calls the project unplannable.
        $this->project->forceFill(['minimum_volume' => 20])->save();

        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('limpeza casa lisboa', volume: 30, difficulty: 20),
            new KeywordIdea('limpeza profunda lisboa', volume: 70, difficulty: 50),
            new KeywordIdea('limpeza apartamento lisboa', volume: 40, difficulty: 30),
            new KeywordIdea('genuinely nothing', volume: 0, difficulty: 10),
        ]);

        $run = $this->research();

        $this->assertNotSame(PipelineRunStatus::Failed, $run->refresh()->status);

        $queries = ContentItem::query()->pluck('target_query')->all();

        $this->assertCount(3, $queries);
        $this->assertNotContains('genuinely nothing', $queries);
    }

    #[Test]
    public function a_competitors_brand_never_becomes_an_article(): void
    {
        // The long tail around a local service term is full of rivals. This
        // project was about to publish an article targeting a competitor's
        // company name — which ranks their brand on our site and sends the
        // click to whoever the searcher was looking for.
        $this->project->forceFill([
            'competitors' => ['cleann.pt', 'https://www.helpling.pt/'],
            'minimum_volume' => 5,
        ])->save();

        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('cleann.pt cleaning company in lisbon', volume: 90, difficulty: 10),
            new KeywordIdea('helpling reviews lisbon', volume: 80, difficulty: 10),
            new KeywordIdea('window cleaning lisbon', volume: 70, difficulty: 20),
            new KeywordIdea('office window cleaning', volume: 60, difficulty: 20),
            new KeywordIdea('window cleaning prices', volume: 50, difficulty: 20),
        ]);

        $this->research();

        $queries = ContentItem::query()->pluck('target_query')->all();

        $this->assertNotContains('cleann.pt cleaning company in lisbon', $queries);
        $this->assertNotContains('helpling reviews lisbon', $queries);
        $this->assertContains('window cleaning lisbon', $queries);
    }

    #[Test]
    public function the_filter_is_told_to_reject_rival_brands_and_other_cities(): void
    {
        $this->brief('A cleaning company in Lisbon.');

        $this->research();

        $judged = collect($this->models->sent())->first(
            fn ($request): bool => str_contains($request->instructions, 'do NOT belong')
        );

        $this->assertNotNull($judged);

        // Both of these reached a live calendar for this project: an article
        // targeting "superlimpa empresa de limpeza lisboa" ranks a competitor's
        // name on our own site, and one targeting "limpeza pós obra porto"
        // earns traffic from a city the business cannot serve. Neither is a
        // different industry, so the original wording caught neither.
        $this->assertStringContainsString('names another company', $judged->instructions);
        $this->assertStringContainsString('not listed above as competitors', $judged->instructions);
        $this->assertStringContainsString('a place this business does not serve', $judged->instructions);
    }

    #[Test]
    public function a_keyword_from_another_industry_is_dropped(): void
    {
        // The real case this exists for: a cleaning company in Lisbon seeded
        // with "limpeza profunda lisboa" gets back "limpeza de pele profunda
        // lisboa" — deep *skin* cleansing, a facial treatment — with a real
        // search volume and nothing marking it as a different business.
        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('limpeza profunda lisboa', volume: 70, difficulty: 50),
            new KeywordIdea('limpeza de pele profunda lisboa', volume: 40, difficulty: 10),
            new KeywordIdea('limpeza casa lisboa', volume: 30, difficulty: 50),
            new KeywordIdea('limpeza apartamento lisboa', volume: 40, difficulty: 30),
        ]);

        $this->project->forceFill(['minimum_volume' => 20])->save();
        $this->brief('A home-cleaning company serving flats in Lisbon.');
        $this->models->willAnswerRole('utility', 'limpeza de pele profunda lisboa');

        $this->research();

        $queries = ContentItem::query()->pluck('target_query')->all();

        $this->assertNotContains('limpeza de pele profunda lisboa', $queries);
        $this->assertContains('limpeza casa lisboa', $queries);
    }

    #[Test]
    public function everything_being_judged_off_topic_keeps_the_pool(): void
    {
        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('worth writing', volume: 900, difficulty: 20),
            new KeywordIdea('also worth writing', volume: 800, difficulty: 25),
            new KeywordIdea('worth writing too', volume: 700, difficulty: 30),
        ]);

        $this->brief('A window-cleaning company.');

        // A model that rejects everything has misread the brief, not found a
        // business with no keywords. Writing about the wrong topic costs one
        // article; emptying the pool costs the whole month.
        $this->models->willAnswerRole(
            'utility',
            'worth writing
also worth writing
worth writing too',
        );

        $this->research();

        $this->assertCount(3, ContentItem::query()->get());
    }

    #[Test]
    public function a_relevance_answer_that_invents_terms_drops_nothing(): void
    {
        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('worth writing', volume: 900, difficulty: 20),
            new KeywordIdea('also worth writing', volume: 800, difficulty: 25),
            new KeywordIdea('worth writing too', volume: 700, difficulty: 30),
        ]);

        // Paraphrased, translated, or simply made up. Matched against what the
        // model was actually shown, so an answer like this drops nothing rather
        // than appearing to work.
        $this->models->willAnswerRole('utility', 'something nobody offered
another invention');

        $this->research();

        $this->assertCount(3, ContentItem::query()->get());
    }

    #[Test]
    public function a_model_outage_does_not_take_research_down_with_it(): void
    {
        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('worth writing', volume: 900, difficulty: 20),
            new KeywordIdea('also worth writing', volume: 800, difficulty: 25),
            new KeywordIdea('worth writing too', volume: 700, difficulty: 30),
        ]);

        $this->brief('A window-cleaning company.');
        $this->models->willThrow(fn () => new RuntimeException('the provider is down'));

        $run = $this->research();

        // Research needed no model at all until the relevance filter existed.
        // A provider outage must not now cost the whole month over a filter.
        $this->assertNotSame(PipelineRunStatus::Failed, $run->refresh()->status);
        $this->assertCount(3, ContentItem::query()->get());
    }

    #[Test]
    public function intent_comes_from_the_shape_of_the_query(): void
    {
        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('how to clean windows', volume: 900, difficulty: 20),
            new KeywordIdea('best window cleaner', volume: 800, difficulty: 20),
            new KeywordIdea('window cleaning cost', volume: 700, difficulty: 20),
            new KeywordIdea('window cleaning near me', volume: 600, difficulty: 20),
        ]);

        $this->research();

        $byQuery = ContentItem::query()->get()->keyBy('target_query');

        $this->assertSame(SearchIntent::Informational, $byQuery['how to clean windows']->intent);
        $this->assertSame(SearchIntent::Commercial, $byQuery['best window cleaner']->intent);
        $this->assertSame(SearchIntent::Transactional, $byQuery['window cleaning cost']->intent);
        $this->assertSame(SearchIntent::Navigational, $byQuery['window cleaning near me']->intent);
    }

    #[Test]
    public function a_project_with_no_seeds_fails_rather_than_inventing_them(): void
    {
        $bare = Project::factory()->create(['research_seeds' => []]);

        $run = app(PipelineRunner::class)->start('research', $bare, []);

        $run = PipelineRun::acrossProjects()->whereKey($run->getKey())->firstOrFail();

        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame('fetch_keywords', $run->failed_step_key);
        // Terminal, not retryable: no amount of backoff supplies a seed.
        $this->assertFalse($run->error['retryable']);
    }

    #[Test]
    public function the_run_is_metered_like_any_other(): void
    {
        $run = $this->research()->refresh();

        // Exit criterion 4: the cost of both pipelines is visible in phase 3's
        // metering. Research used to call no model at all; it now makes exactly
        // one, to drop keywords that belong to a different industry — a
        // cleaning company seeded with "limpeza profunda" is otherwise handed
        // "limpeza de pele profunda", which is a facial treatment, and writes
        // an article about it. One call for the whole pool is the cheapest
        // form that judgement takes.
        // Every step the pipeline declares, metered — as a count taken from the
        // definition rather than a number written here. A literal broke twice
        // on this file when a step was added, and what it was protecting was
        // never the number: it was "nothing runs unmetered".
        $this->assertSame(
            count((new ResearchPipeline)->steps()),
            $run->steps()->count(),
        );

        $this->assertSame(0, $run->cost_micros);

        foreach ($run->steps()->get() as $step) {
            $this->assertNotNull($step->latency_ms);
        }
    }

    private function brief(string $positioning): void
    {
        BrandBrief::revise($this->project, ['positioning' => $positioning]);
    }

    /** @param array<string, mixed> $input */
    private function research(array $input = []): PipelineRun
    {
        return app(PipelineRunner::class)->start('research', $this->project, $input);
    }
}
