<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\PipelineRunStatus;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Research\Contracts\KeywordSource;
use App\Research\FakeKeywordSource;
use App\Research\KeywordIdea;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Proposing topics from the brief, then checking them against real demand.
 *
 * The step exists because expansion could only ever return rewordings of its
 * seed — measured on this project as sixteen ideas, nine of them the same
 * phrase. So the tests here are mostly about the two halves of "propose, then
 * check": that a proposal nobody searches for is dropped by *measurement* and
 * not by taste, and that the whole thing failing costs the pool nothing it
 * already had.
 */
final class ProposeAnglesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeKeywordSource $keywords;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'name' => 'Cleaning Point',
            'research_seeds' => ['window cleaning'],
            'market' => 'pt',
            'default_locale' => 'pt-PT',
            'minimum_volume' => 10,
        ]);
        app(CurrentProject::class)->set($this->project);

        BrandBrief::revise($this->project, [
            'positioning' => 'A managed cleaning company in Lisbon that handles keys for absent owners.',
            'audience' => 'Expat property owners.',
        ]);

        /** @var FakeKeywordSource $keywords */
        $keywords = app(KeywordSource::class);
        $this->keywords = $keywords;

        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;

        config()->set('queue.default', 'sync');
    }

    #[Test]
    public function a_proposed_topic_reaches_the_pool_when_somebody_searches_for_it(): void
    {
        $this->propose(['rejunte encardido', 'gestao de chaves limpeza']);

        $this->keywords->willMeasure([
            'rejunte encardido' => [70, 3],
            'gestao de chaves limpeza' => [110, 6],
        ]);

        $this->research();

        $queries = ContentItem::query()->pluck('target_query')->all();

        // Neither of these contains the seed "window cleaning", so no amount of
        // expansion would ever have produced them. That is the whole point.
        $this->assertContains('rejunte encardido', $queries);
        $this->assertContains('gestao de chaves limpeza', $queries);
    }

    #[Test]
    public function a_topic_the_vendor_has_never_heard_of_is_dropped(): void
    {
        // Angle 1 is "a problem somebody wants gone" — the kind of thing people
        // type and a keyword tool therefore knows about. Unpriced here means
        // invented, and gets no benefit of the doubt.
        $this->propose(['rejunte encardido', 'limpeza quantica holistica'], angle: 1);

        // The invented one is simply absent from the measurement — which is how
        // this vendor says "no such phrase", and the strongest available signal
        // that the model made it up.
        $this->keywords->willMeasure(['rejunte encardido' => [70, 3]]);

        $this->research();

        $queries = ContentItem::query()->pluck('target_query')->all();

        $this->assertContains('rejunte encardido', $queries);
        $this->assertNotContains('limpeza quantica holistica', $queries);
    }

    #[Test]
    public function a_real_topic_below_the_projects_floor_is_dropped_too(): void
    {
        $this->propose(['rejunte encardido', 'cheiro a tabaco']);

        // Measured, real, and too small to plan a month from. A different fact
        // from "never heard of it", and the run records both counts so the two
        // can be told apart.
        $this->keywords->willMeasure([
            'rejunte encardido' => [70, 3],
            'cheiro a tabaco' => [2, 1],
        ]);

        $run = $this->research();

        $this->assertNotContains('cheiro a tabaco', ContentItem::query()->pluck('target_query')->all());

        $this->assertSame(2, $run->context['research.angles_proposed'] ?? null);
        $this->assertSame(2, $run->context['research.angles_measured'] ?? null);
        $this->assertSame(1, $run->context['research.angles_kept'] ?? null);
    }

    #[Test]
    public function it_proposes_in_the_language_the_project_publishes_in(): void
    {
        $this->propose(['rejunte encardido']);
        $this->keywords->willMeasure(['rejunte encardido' => [70, 3]]);

        $this->research();

        $this->assertSame('pt-PT', $this->keywords->measured()[0]['language']);
        $this->assertSame('pt', $this->keywords->measured()[0]['market']);

        // The instruction has to name the language, because a model handed an
        // English brief answers in English by default and the market's index
        // has never seen those phrases.
        $asked = collect($this->models->sent())->first(
            fn ($request): bool => str_contains($request->instructions, 'QUERY:')
        );

        $this->assertNotNull($asked);
        $this->assertStringContainsString('Write every query in pt-PT,', $asked->instructions);
    }

    #[Test]
    public function it_proposes_in_a_language_the_market_can_be_measured_in(): void
    {
        // The project publishes in English; the market only has keyword data in
        // Portuguese. This exact combination produced 60 proposed and 0
        // measured on the first live run — the phrases were fine and the vendor
        // had simply never indexed that language for that country.
        $this->project->forceFill(['default_locale' => 'en'])->save();
        $this->keywords->willSpeak('pt');

        $this->propose(['rejunte encardido']);
        $this->keywords->willMeasure(['rejunte encardido' => [70, 3]]);

        $this->research();

        $asked = collect($this->models->sent())->first(
            fn ($request): bool => str_contains($request->instructions, 'QUERY:')
        );

        $this->assertNotNull($asked);
        $this->assertStringContainsString('Write every query in pt,', $asked->instructions);
        $this->assertStringNotContainsString('Write every query in en,', $asked->instructions);
    }

    #[Test]
    public function a_sentence_is_not_sent_to_be_measured(): void
    {
        $this->propose([
            'rejunte encardido',
            'what is the best way to remove limescale from a shower screen in an apartment in lisbon',
        ]);

        $this->keywords->willMeasure(['rejunte encardido' => [70, 3]]);

        $this->research();

        // The measuring endpoint caps a phrase at eighty characters and rejects
        // the whole batch over one that is too long — so an over-long proposal
        // has to be dropped here rather than costing every other proposal.
        $this->assertSame(['rejunte encardido'], $this->keywords->measured()[0]['keywords']);
    }

    #[Test]
    public function the_same_topic_proposed_twice_is_measured_once(): void
    {
        $this->propose(['rejunte encardido', 'Rejunte Encardido', 'rejunte encardido']);
        $this->keywords->willMeasure(['rejunte encardido' => [70, 3]]);

        $this->research();

        // Lower-cased on the way in, so a model that repeats itself does not
        // pay to measure the same phrase three times.
        $this->assertSame(['rejunte encardido'], $this->keywords->measured()[0]['keywords']);
    }

    #[Test]
    public function a_business_specific_topic_survives_having_no_search_volume(): void
    {
        // Angle 3 is "a customer this business serves that others do not".
        // Google Ads prices four of the twenty topics on a competitor's real
        // calendar; the sixteen it cannot price are the ones worth copying, so
        // a few of those come through unpriced.
        $this->propose(['limpeza para expatriados', 'gestao de chaves limpeza'], angle: 3);

        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('worth writing', volume: 900, difficulty: 20),
            new KeywordIdea('also worth writing', volume: 800, difficulty: 25),
        ]);

        $run = $this->research();

        $queries = ContentItem::query()->pluck('target_query')->all();

        $this->assertContains('limpeza para expatriados', $queries);
        $this->assertSame(2, $run->context['research.angles_unpriced_kept'] ?? null);

        // Proposed in Portuguese, so written in Portuguese. Nothing else can
        // say what language these are in — the vendor never answered about
        // them — and defaulting to the project locale stamps a Portuguese topic
        // English and writes it in English.
        $this->assertSame(
            'pt-PT',
            ContentItem::query()->where('target_query', 'limpeza para expatriados')->value('locale'),
        );
    }

    #[Test]
    public function the_allowance_is_bounded(): void
    {
        config()->set('research.unmeasured_angles_kept', 1);

        $this->propose(['limpeza para expatriados', 'gestao de chaves limpeza'], angle: 4);

        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('worth writing', volume: 900, difficulty: 20),
            new KeywordIdea('also worth writing', volume: 800, difficulty: 25),
        ]);

        $run = $this->research();

        // A month made entirely of topics nobody can price is a month built on
        // nobody's opinion but the model's.
        $this->assertSame(1, $run->context['research.angles_unpriced_kept'] ?? null);
    }

    // ------------------------------------------------------------ failing open

    #[Test]
    public function a_model_outage_costs_the_proposals_and_nothing_else(): void
    {
        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('worth writing', volume: 900, difficulty: 20),
            new KeywordIdea('also worth writing', volume: 800, difficulty: 25),
            new KeywordIdea('worth writing too', volume: 700, difficulty: 30),
        ]);

        $this->models->willThrow(fn () => new RuntimeException('the provider is down'));

        $run = $this->research();

        // Research managed with no model call at all until these steps existed.
        // A step that only ever *adds* to the pool must not be able to empty it.
        $this->assertNotSame(PipelineRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(3, ContentItem::query()->count());
    }

    #[Test]
    public function the_expanded_half_survives_the_proposed_half_failing_to_measure(): void
    {
        $this->propose(['rejunte encardido']);

        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('worth writing', volume: 900, difficulty: 20),
            new KeywordIdea('also worth writing', volume: 800, difficulty: 25),
            new KeywordIdea('worth writing too', volume: 700, difficulty: 30),
        ]);

        // Nothing scripted, so nothing measures — the vendor recognised none of
        // it, and angle 1 earns no allowance. Not a failure, just an empty
        // proposed half.
        $run = $this->research();

        $this->assertNotSame(PipelineRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(3, ContentItem::query()->count());
    }

    #[Test]
    public function both_halves_land_in_one_pool_without_double_counting(): void
    {
        $this->propose(['worth writing', 'rejunte encardido']);

        $this->keywords->willReturn('window cleaning', [
            new KeywordIdea('worth writing', volume: 900, difficulty: 20),
            new KeywordIdea('also worth writing', volume: 800, difficulty: 25),
        ]);

        $this->keywords->willMeasure([
            'worth writing' => [55, 40],
            'rejunte encardido' => [70, 3],
        ]);

        $run = $this->research();

        $queries = ContentItem::query()->pluck('target_query')->all();

        $this->assertContains('rejunte encardido', $queries);

        // "worth writing" came from both halves. One idea, and it keeps the
        // reading the vendor took through its own index rather than the one
        // taken of a phrase we proposed.
        $this->assertSame(1, collect($queries)->filter(fn ($q): bool => $q === 'worth writing')->count());
        $this->assertSame(900, (int) ContentItem::query()->where('target_query', 'worth writing')->value('topic_volume'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Script the proposals, and then a benign answer for the relevance filter
     * that runs after them.
     *
     * A queue rather than an answer-per-role: both steps ask the `utility`
     * role, so a single role answer hands the relevance filter the proposal
     * list — and since that filter rejects any pool keyword it finds named in
     * the reply, the proposals would reject themselves.
     *
     * @param  list<string>  $queries
     */
    private function propose(array $queries, int $angle = 1): void
    {
        $this->models->willAnswer([
            implode("\n", array_map(
                static fn (string $query): string => "QUERY: {$query} | {$angle}",
                $queries,
            )),
            'All of these are about this business.',
        ]);
    }

    private function research(): PipelineRun
    {
        return app(PipelineRunner::class)->start('research', $this->project)->refresh();
    }
}
