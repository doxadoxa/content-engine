<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelRequest;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PipelineRunStatus;
use App\Media\Contracts\ImageGenerationProvider;
use App\Media\FakeImageGeneration;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRegistry;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Steps\Generation\BuildGeoLayer;
use App\Pipelines\Steps\Generation\CompileBrief;
use App\Pipelines\Steps\Generation\CoverEntities;
use App\Pipelines\Steps\Generation\FactCheck;
use App\Pipelines\Steps\Generation\FinaliseDraft;
use App\Pipelines\Steps\Generation\LinkToSite;
use App\Pipelines\Steps\Generation\VerifyLinks;
use App\Pipelines\Steps\Generation\WriteDraft;
use App\Research\Contracts\KeywordSource;
use App\Research\FakeKeywordSource;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 5's exit criteria: a planned unit walks the whole pipeline into `draft`
 * with its GEO layer filled; a YMYL unit cannot reach `draft` on a failed
 * fact-check; and the article's cost is known by step.
 */
final class GenerationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'original_data' => ['call_out_fee' => '€45', 'coverage' => 'Lisbon only'],
        ]);
        app(CurrentProject::class)->set($this->project);

        BrandBrief::revise($this->project, ['tone' => 'Plain and practical.']);

        /** @var FakeModelGateway $gateway */
        $gateway = app(ModelGateway::class);
        $this->models = $gateway;

        $this->scriptAGoodArticle();

        config()->set('queue.default', 'sync');
    }

    // ------------------------------------------------------- exit criterion 1

    #[Test]
    public function a_planned_unit_becomes_a_draft_with_its_geo_layer_filled(): void
    {
        $unit = $this->unit();

        $run = $this->generate($unit);

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $unit->refresh();

        $this->assertSame(ContentItemState::Draft, $unit->state);
        $this->assertNotEmpty($unit->body_markdown);
        $this->assertNotEmpty($unit->body_html);
        $this->assertNotEmpty($unit->summary);
        $this->assertNotSame([], $unit->outline);

        // §5.3, and §1's first differentiator.
        $this->assertSame('HowTo', $unit->json_ld['@type']);
        $this->assertSame('FAQPage', $unit->faq_json_ld['@type']);
        $this->assertNotSame([], $unit->quotable_blocks);
        $this->assertNotSame([], $unit->entity_coverage);
    }

    #[Test]
    public function the_body_is_rendered_to_html_as_well_as_markdown(): void
    {
        $unit = $this->unit();
        $this->generate($unit);

        // §5's payload carries both (spec §5).
        $this->assertStringContainsString('<h2>', $unit->refresh()->body_html);
    }

    #[Test]
    public function entity_coverage_is_measured_against_the_text(): void
    {
        $unit = $this->unit(['entities' => ['Lisbon', 'a thing nobody wrote about']]);

        $this->generate($unit);

        $coverage = $unit->refresh()->entity_coverage;

        // Checkable rather than declarative (§5.3): the model that wrote the
        // article has no say in whether it covered anything.
        $this->assertTrue($coverage['Lisbon']);
        $this->assertFalse($coverage['a thing nobody wrote about']);
    }

    #[Test]
    public function quotable_blocks_come_out_of_the_article(): void
    {
        $unit = $this->unit();
        $this->generate($unit);

        $unit->refresh();

        foreach ($unit->quotable_blocks as $block) {
            // A quote the article cannot support is worse than no quote.
            $this->assertStringContainsString(mb_substr($block, 0, 40), $unit->body_markdown);
            $this->assertStringStartsNotWith('#', $block);
        }
    }

    #[Test]
    public function the_schema_names_the_author(): void
    {
        $unit = $this->unit();
        $this->generate($unit);

        $this->assertSame('Operator', $unit->refresh()->json_ld['author']['name']);
    }

    #[Test]
    public function the_unit_records_which_brief_version_wrote_it(): void
    {
        $unit = $this->unit();
        $this->generate($unit);

        $active = BrandBrief::activeFor($this->project);

        // §2's promise: a published unit can always be traced to the voice it
        // was written from.
        $this->assertSame($active?->getKey(), $unit->refresh()->brand_brief_id);
    }

    #[Test]
    public function the_state_machine_is_walked_rather_than_jumped(): void
    {
        $unit = $this->unit();

        $this->assertSame(ContentItemState::Idea, $unit->state);

        $this->generate($unit);

        // idea → queued → generating → draft, all through transitionTo().
        $this->assertSame(ContentItemState::Draft, $unit->refresh()->state);
    }

    #[Test]
    public function original_data_reaches_the_model_only_when_the_planner_asked(): void
    {
        $this->generate($this->unit(['needs_original_data' => true]));

        $withData = collect($this->models->sent())->contains(
            fn ($request): bool => str_contains($request->prompt, 'call_out_fee')
        );

        $this->assertTrue($withData);

        // A guide that never needed prices should not be handed the price list.
        $before = $this->models->callCount();

        $this->generate($this->unit([
            'needs_original_data' => false,
            'slug' => 'another-unit',
        ]));

        $laterPrompts = array_slice($this->models->sent(), $before);

        $this->assertFalse(collect($laterPrompts)->contains(
            fn ($request): bool => str_contains($request->prompt, 'call_out_fee')
        ));
    }

    // ------------------------------------------------------- exit criterion 2

    #[Test]
    public function a_ymyl_unit_cannot_become_a_draft_on_a_failed_factcheck(): void
    {
        $ymyl = Project::factory()->ymyl()->create();
        app(CurrentProject::class)->set($ymyl);
        BrandBrief::revise($ymyl, ['tone' => 'Precise and sourced.']);

        $this->scriptAGoodArticle('The €45 fee is not supported by anything supplied.');

        $unit = ContentItem::factory()->create([
            'title' => 'Staking yields explained',
            'target_query' => 'staking yields',
            'entities' => ['staking'],
        ]);

        $run = app(PipelineRunner::class)->start('generation', $ymyl, [], $unit->getKey());

        $run = PipelineRun::acrossProjects()->whereKey($run->getKey())->firstOrFail();

        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame(FinaliseDraft::key(), $run->failed_step_key);
        $this->assertFalse($run->error['retryable']);

        $unit->refresh();

        // §5.2: it does not reach draft. It keeps its body and its findings, so
        // an operator can see what to fix.
        $this->assertSame(ContentItemState::Generating, $unit->state);
        $this->assertNotEmpty($unit->body_markdown);
        $this->assertFalse($unit->factcheck['passed']);
        $this->assertNotSame([], $unit->factcheck['findings']);
    }

    #[Test]
    public function a_non_ymyl_unit_still_drafts_with_findings_recorded(): void
    {
        $this->scriptAGoodArticle('The €45 fee is not supported.');

        $unit = $this->unit();
        $run = $this->generate($unit);

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $unit->refresh();

        // Reviewable, with the problem attached — a human decides.
        $this->assertSame(ContentItemState::Draft, $unit->state);
        $this->assertFalse($unit->factcheck['passed']);
        $this->assertFalse($unit->factcheck['required']);
    }

    #[Test]
    public function a_ymyl_project_without_an_author_refuses_to_generate(): void
    {
        $ymyl = Project::factory()->ymyl()->create(['authors' => []]);
        app(CurrentProject::class)->set($ymyl);
        BrandBrief::revise($ymyl, ['tone' => 'Precise.']);

        $unit = ContentItem::factory()->create(['target_query' => 'staking']);

        $run = app(PipelineRunner::class)->start('generation', $ymyl, [], $unit->getKey());
        $run = PipelineRun::acrossProjects()->whereKey($run->getKey())->firstOrFail();

        // §1: author schema with real names is required on YMYL.
        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame(CompileBrief::key(), $run->failed_step_key);
    }

    #[Test]
    public function a_project_without_a_brief_refuses_to_generate(): void
    {
        $bare = Project::factory()->create();
        app(CurrentProject::class)->set($bare);

        $unit = ContentItem::factory()->create(['target_query' => 'anything']);

        $run = app(PipelineRunner::class)->start('generation', $bare, [], $unit->getKey());
        $run = PipelineRun::acrossProjects()->whereKey($run->getKey())->firstOrFail();

        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame(CompileBrief::key(), $run->failed_step_key);
    }

    // ------------------------------------------------------- exit criterion 3

    #[Test]
    public function the_length_target_is_measured_once_and_kept(): void
    {
        $unit = $this->unit();

        /** @var FakeKeywordSource $keywords */
        $keywords = app(KeywordSource::class);

        $keywords->willRank([
            ['url' => 'https://a.test/x', 'title' => 'A'],
            ['url' => 'https://b.test/x', 'title' => 'B'],
            ['url' => 'https://c.test/x', 'title' => 'C'],
        ]);

        Http::fake(['*' => Http::response(
            '<html><body><p>'.str_repeat('word ', 1800).'</p></body></html>',
        )]);

        $this->generate($unit);

        $measured = $unit->refresh()->serp_target_words;

        $this->assertNotNull($measured);

        // Reading it costs eight fetches from other people's servers, and an
        // article is regenerated on every refresh and every retry.
        $before = count(Http::recorded());

        $this->generate($unit);

        $this->assertSame($measured, $unit->refresh()->serp_target_words);
        $this->assertSame($before, count(Http::recorded()));
    }

    #[Test]
    public function a_rewrite_is_told_what_was_wrong_last_time(): void
    {
        $unit = $this->unit();

        // What the approvals screen writes when somebody sends an article back.
        $unit->forceFill([
            'review' => ['reason' => 'too_thin', 'note' => 'Two sentences on pricing is not a section.'],
            'reviewed_at' => now(),
        ])->save();

        $this->generate($unit);

        $instructions = array_map(
            static fn (ModelRequest $request): string => $request->instructions,
            $this->models->sent(),
        );

        $written = implode("\n", $instructions);

        // Both writing steps read the compiled brief, so the outline and the
        // draft answer the same objection. A rewrite that cannot see the
        // complaint is a rewrite that reproduces it.
        $this->assertStringContainsString('sent back', $written);
        $this->assertStringContainsString('Too thin', $written);
        $this->assertStringContainsString('Two sentences on pricing is not a section.', $written);
    }

    #[Test]
    public function a_first_draft_is_told_nothing_about_a_review(): void
    {
        $this->generate();

        $written = implode("\n", array_map(
            static fn (ModelRequest $request): string => $request->instructions,
            $this->models->sent(),
        ));

        // Nothing was sent back, so nothing is said about it. A line that is
        // always there is a line the model stops reading.
        $this->assertStringNotContainsString('sent back', $written);
    }

    #[Test]
    public function an_article_that_lost_its_inline_pictures_says_why(): void
    {
        /** @var FakeImageGeneration $images */
        $images = app(ImageGenerationProvider::class);

        // The hero draws, then the balance runs out — which is exactly what
        // happened on this project: articles kept shipping with one picture and
        // the only trace was a log line on a container's stderr.
        $images->failAfter(1);

        $run = $this->generate()->refresh();

        // Still a success. An article that is written and paid for must not be
        // lost over decoration.
        $this->assertSame(PipelineRunStatus::Completed, $run->status);

        // But the run says so, where somebody already looks. Every article
        // after this one has the same problem, so silence is the wrong answer.
        $this->assertSame(0, $run->context['media.inline_placed'] ?? null);
        $this->assertSame(3, $run->context['media.inline_wanted'] ?? null);
        $this->assertStringContainsString(
            'insufficient balance',
            (string) ($run->context['media.inline_stopped_because'] ?? ''),
        );
    }

    #[Test]
    public function a_fully_illustrated_article_says_nothing(): void
    {
        $run = $this->generate()->refresh();

        // No note when there is nothing wrong. A field that is always present
        // is a field nobody reads.
        $this->assertArrayNotHasKey('media.inline_stopped_because', $run->context);
        $this->assertArrayNotHasKey('media.inline_placed', $run->context);
    }

    #[Test]
    public function an_empty_image_balance_does_not_hold_a_finished_article_hostage(): void
    {
        /** @var FakeImageGeneration $images */
        $images = app(ImageGenerationProvider::class);

        // Not one picture, including the hero — which is what an exhausted
        // account actually looks like once it is properly empty.
        $images->failAfter(0);

        $run = $this->generate()->refresh();
        $unit = ContentItem::query()->latest('updated_at')->firstOrFail();

        // The article is written, scored and publishable. Failing the run over
        // a picture account leaves it marooned behind a red run.
        $this->assertSame(PipelineRunStatus::Completed, $run->status);
        $this->assertSame(ContentItemState::Draft, $unit->state);
        $this->assertNotEmpty($unit->body_markdown);

        $this->assertStringContainsString(
            'insufficient balance',
            (string) ($run->context['media.stopped_because'] ?? ''),
        );
    }

    #[Test]
    public function the_article_is_named_by_what_was_written_not_by_the_search_query(): void
    {
        $this->models->willAnswerRole('draft', <<<'MD'
        # Cleaning a lacquered white door without stripping the finish

        ## Why windows get dirty

        Prose that runs for a while so the article is not empty.

        Summary: A short line under the limit.
        MD);

        $unit = $this->unit(['title' => 'Empresa De Limpeza Em Lisboa', 'slug' => 'empresa-de-limpeza-em-lisboa']);

        $this->generate($unit);

        $unit->refresh();

        // The title was the keyword in title case, set when the idea was stored
        // and never replaced — which is how a piece ends up called "Empresa De
        // Limpeza Em Lisboa" rather than something a person would click.
        $this->assertNotSame('Empresa De Limpeza Em Lisboa', $unit->title);
        $this->assertStringStartsWith('# '.$unit->title, ltrim((string) $unit->body_markdown));

        // And the URL follows the headline, in the headline's language. A
        // locale variant used to inherit the source slug verbatim, so the
        // English edition of a Portuguese article lived at a Portuguese URL.
        $this->assertSame(Str::slug((string) $unit->title), $unit->slug);
    }

    #[Test]
    public function a_body_with_no_heading_keeps_the_title_it_had(): void
    {
        $this->models->willAnswerRole('draft', "No heading here, just prose about cleaning that runs on.\n\n## A section\n\nMore prose.");

        $unit = $this->unit(['title' => 'Kept As It Was', 'slug' => 'kept-as-it-was']);

        $this->generate($unit);

        // Better a keyword-shaped title than none: the column is not nullable
        // and a blank headline is worse than a dull one.
        $this->assertSame('Kept As It Was', $unit->refresh()->title);
    }

    #[Test]
    public function a_long_cyrillic_draft_does_not_kill_the_run(): void
    {
        // Str::squish runs `/u` regexes and preg_replace returns null rather
        // than the subject when one fails. Every article here was Latin text
        // until this project added Russian, and the first Cyrillic draft failed
        // the whole run on a TypeError three lines downstream of it.
        $this->models->willAnswerRole('draft', implode("\n\n", [
            '# Как выбрать клининговую компанию в Лиссабоне',
            '## Что входит в регулярную уборку',
            // Long enough to be a realistic block, in a script where every
            // character is two bytes.
            str_repeat('Чистая проза здесь для объёма текста статьи. ', 200),
            'Summary: Короткая строка о выборе клининговой компании.',
        ]));

        $run = $this->generate()->refresh();

        $this->assertSame(PipelineRunStatus::Completed, $run->status);
    }

    #[Test]
    public function the_serp_is_read_in_the_language_the_article_is_written_in(): void
    {
        /** @var FakeKeywordSource $keywords */
        $keywords = app(KeywordSource::class);

        $this->generate();

        $this->assertNotEmpty($keywords->ranked());

        // Both reads — the length target and the citation candidates — take the
        // brief's locale rather than the project's market. A word count
        // measured off Portuguese pages and applied to an English article is a
        // number with nothing behind it, and a citation list from the wrong
        // SERP is a set of links this article has no reason to carry.
        foreach ($keywords->ranked() as $call) {
            $this->assertSame('en', $call['language']);
        }
    }

    #[Test]
    public function the_cost_of_an_article_is_known_by_step(): void
    {
        $run = $this->generate()->refresh();

        // Ten: the seven original steps, plus the hero image, plus checking
        // the citations the draft makes and linking to the site's own pages —
        // the two things that were leaving articles scoring 56 out of 100.
        $this->assertSame(10, $run->steps()->count());
        $this->assertGreaterThan(0, $run->cost_micros);

        $byKey = $run->steps()->get()->keyBy('step_key');

        // The three steps that call a model carry cost; the ones that do not
        // say so with nulls rather than an unexplained zero.
        $this->assertGreaterThan(0, $byKey['write_draft']->cost_micros);
        $this->assertGreaterThan(0, $byKey[FactCheck::key()]->cost_micros);
        $this->assertGreaterThan(0, $byKey[BuildGeoLayer::key()]->cost_micros);
        $this->assertNull($byKey[CoverEntities::key()]->model);
        $this->assertSame(0, $byKey[CoverEntities::key()]->cost_micros);
    }

    #[Test]
    public function the_expensive_steps_are_on_the_expensive_queue(): void
    {
        $graph = app(PipelineRegistry::class)->graph('generation');

        $expensive = config('pipeline.queues.expensive');
        $cheap = config('pipeline.queues.cheap');

        // §3.2: one long generation must not occupy the pool every quick step
        // in every other run is behind.
        $this->assertSame($expensive, $graph->step('write_outline')->queue());
        $this->assertSame($expensive, $graph->step('write_draft')->queue());
        $this->assertSame($expensive, $graph->step(FactCheck::key())->queue());
        $this->assertSame($cheap, $graph->step(CoverEntities::key())->queue());
        $this->assertSame($cheap, $graph->step(FinaliseDraft::key())->queue());
    }

    #[Test]
    public function the_three_checks_run_in_parallel(): void
    {
        $run = $this->generate();

        $positions = $run->steps()->get()->mapWithKeys(
            fn ($step): array => [$step->step_key => $step->position]
        )->all();

        // Asserted as an ordering rather than as literal indices: the ordering
        // is what the DAG promises, and an index fails the next time a step is
        // added without anything having gone wrong.
        $this->assertLessThan(
            $positions[WriteDraft::key()],
            $positions[CompileBrief::key()],
            'The brief has to be compiled before anything is written from it.',
        );

        // Several unrelated questions about one body, side by side, and all of
        // them before the body is saved.
        foreach ([
            FactCheck::key(),
            BuildGeoLayer::key(),
            CoverEntities::key(),
            VerifyLinks::key(),
            LinkToSite::key(),
        ] as $key) {
            $this->assertLessThan(
                $positions[FinaliseDraft::key()],
                $positions[$key],
                "{$key} runs after the draft is saved, so its result is thrown away.",
            );
            $this->assertGreaterThan(
                $positions[WriteDraft::key()],
                $positions[$key],
                "{$key} runs before there is a draft to work on.",
            );
        }
    }

    #[Test]
    public function a_findings_list_mentioning_pass_is_not_read_as_passing(): void
    {
        // "does not pass" contains "PASS". Searching for the substring turned a
        // finding into a clean bill of health.
        $this->scriptAGoodArticle('The €45 figure does not pass verification.');

        $unit = $this->unit();
        $this->generate($unit);

        $unit->refresh();

        $this->assertFalse($unit->factcheck['passed']);
        $this->assertNotSame([], $unit->factcheck['findings']);
    }

    #[Test]
    public function a_bare_pass_is_still_a_pass(): void
    {
        $this->scriptAGoodArticle('  pass  ');

        $unit = $this->unit();
        $this->generate($unit);

        $this->assertTrue($unit->refresh()->factcheck['passed']);
    }

    #[Test]
    public function a_unit_that_is_already_a_draft_can_be_regenerated(): void
    {
        $unit = $this->unit();
        $this->generate($unit);

        $this->assertSame(ContentItemState::Draft, $unit->refresh()->state);

        // `draft → draft` is not an edge on the state map, so an unconditional
        // transition here failed the run on its last step, after paying for
        // every model call in it.
        $second = $this->generate($unit);

        $this->assertSame(PipelineRunStatus::Completed, $second->refresh()->status);
        $this->assertSame(ContentItemState::Draft, $unit->refresh()->state);
    }

    #[Test]
    public function a_unit_being_refreshed_lands_back_in_draft(): void
    {
        $unit = $this->unit();
        $this->generate($unit);

        $unit->refresh()->approve();
        $unit->markPublished();
        $unit->startRefresh();

        $this->generate($unit);

        // The refresh loop of phase 9 rewrites live text and puts it back in
        // front of a human rather than straight back on the site.
        $this->assertSame(ContentItemState::Draft, $unit->refresh()->state);
    }

    #[Test]
    public function nothing_is_published_by_generation(): void
    {
        $publishing = Project::factory()->create(['autopublish' => true]);
        app(CurrentProject::class)->set($publishing);
        BrandBrief::revise($publishing, ['tone' => 'Plain.']);

        $unit = ContentItem::factory()->create(['target_query' => 'anything', 'entities' => []]);

        app(PipelineRunner::class)->start('generation', $publishing, [], $unit->getKey());

        // §5.4: even with auto-publish on, generation stops at draft. Delivery
        // is phase 6's job and nothing here reaches a reader.
        $this->assertSame(ContentItemState::Draft, $unit->refresh()->state);
        $this->assertNull($unit->published_at);
    }

    /**
     * Scripted by role, not by position: fact_check and build_geo_layer are
     * parallel branches with no guaranteed order between them.
     */
    private function scriptAGoodArticle(string $factCheck = 'PASS'): void
    {
        $this->models
            ->willAnswerRole('outline', "Why windows get dirty\nHow often to clean them\nWhat it costs")
            ->willAnswerRole('factcheck', $factCheck)
            ->willAnswerRole(
                'utility',
                "Q: How much does a visit cost?\nA: The call-out fee is €45. It covers the first hour on site.\n"
                ."Q: Do you work outside Lisbon?\nA: No. We work in Lisbon only.",
            )
            ->willAnswerRole('draft', <<<'MD'
            ## Why windows get dirty

            Lisbon sits on the Atlantic and the salt in the air settles on glass within days of a clean. That is why a flat two streets from the water needs attention on a different schedule from one inland, and why a single annual clean rarely holds.

            ## How often to clean them

            Most flats we look after are on a six-week cycle. That is frequent enough that nothing bakes on in the summer sun, and infrequent enough that nobody is paying for work the glass did not need.

            ## What it costs

            Our call-out fee is €45 and covers the first hour on site. We work in Lisbon only, which is what lets us keep a six-week cycle for the buildings we already know.

            Summary: Lisbon flats need their windows cleaned about every six weeks because Atlantic salt settles on the glass.
            MD);
    }

    /** @param array<string, mixed> $attributes */
    private function unit(array $attributes = []): ContentItem
    {
        return ContentItem::factory()->create([
            'title' => 'How often to clean windows in Lisbon',
            'target_query' => 'window cleaning lisbon',
            'type' => ContentItemType::HowTo,
            'entities' => ['Lisbon', 'Atlantic salt', 'six-week cycle'],
            'needs_original_data' => true,
            ...$attributes,
        ]);
    }

    private function generate(?ContentItem $unit = null): PipelineRun
    {
        $unit ??= $this->unit();

        return app(PipelineRunner::class)->start('generation', $this->project, [], $unit->getKey());
    }
}
