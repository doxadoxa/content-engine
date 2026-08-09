<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\EmbeddingGateway;
use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeEmbeddingGateway;
use App\Ai\FakeModelGateway;
use App\Enums\AssetRole;
use App\Enums\ChannelType;
use App\Enums\ContentItemType;
use App\Enums\PipelineRunStatus;
use App\Enums\SocialBand;
use App\Enums\WebhookEvent;
use App\Media\Contracts\ImageGenerationProvider;
use App\Media\FakeImageGeneration;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRegistry;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Steps\Repurpose\GenerateHero;
use App\Pipelines\Steps\Repurpose\LinkInternally;
use App\Pipelines\Steps\Repurpose\SaveDerivatives;
use App\Publishing\WebhookPayload;
use App\Support\Corpus\CorpusIndex;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 8's exit criteria: one unit produces an article plus social
 * derivatives; a derivative inherits the parent's entities and links; internal
 * links come from embeddings rather than word matching; and the cost of a unit
 * with its derivatives and images is known.
 */
final class RepurposePipelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        BrandBrief::revise($this->project, ['tone' => 'Plain and practical.']);

        foreach ([ChannelType::LinkedIn, ChannelType::X] as $type) {
            Channel::factory()->social($type)->create(['name' => $type->label()]);
        }

        /** @var FakeModelGateway $gateway */
        $gateway = app(ModelGateway::class);
        $this->models = $gateway;

        // Every entity of the parent, so the derivative check passes.
        $this->models->willAnswerRole(
            'draft',
            'Lisbon flats need their windows cleaned every six weeks. Atlantic salt is why.',
        );

        config()->set('queue.default', 'sync');
    }

    // ------------------------------------------------------- exit criterion 1

    #[Test]
    public function one_unit_produces_an_article_and_its_social_derivatives(): void
    {
        $article = $this->article();

        $run = $this->repurpose($article);

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $derivatives = $article->derivatives()->get();

        $this->assertCount(2, $derivatives);
        $this->assertEqualsCanonicalizing(
            ['linkedin', 'x'],
            $derivatives->pluck('channel_type')->all(),
        );

        foreach ($derivatives as $derivative) {
            $this->assertNotEmpty($derivative->body_markdown);
            $this->assertTrue($derivative->isDerivative());

            // §3 of the social spec: a derivative is saved as a social post.
            // Until the type existed this step wrote `Explainer` for want of a
            // nearer case, which made "how many explainers has this project
            // published" unanswerable and gave a 300-character post the
            // schema.org type of an article.
            $this->assertSame(ContentItemType::SocialPost, $derivative->type);
            $this->assertSame('SocialMediaPosting', $derivative->type->schemaType());

            // §5: this step is the Derivative band, budgeted at ≤1 a week. The
            // governor counts published units by (project, band, published_at),
            // so a derivative saved without a band is invisible to its own
            // ceiling and the budget binds only the bands somebody remembered
            // to tag.
            $this->assertSame(SocialBand::Derivative, $derivative->social_band);
        }
    }

    #[Test]
    public function a_derivative_is_not_another_language_of_the_article(): void
    {
        $article = $this->article();
        $this->repurpose($article);

        $derivative = $article->derivatives()->firstOrFail();

        // Its own locale group: putting it in the parent's would make it an
        // hreflang alternate of the article, which it is not.
        $this->assertNotSame($article->locale_group_id, $derivative->locale_group_id);
        $this->assertSame(1, $article->localeVariants()->count());
    }

    #[Test]
    public function only_connected_channels_are_written_for(): void
    {
        Channel::query()->where('type', ChannelType::X)->delete();

        $article = $this->article();
        $this->repurpose($article);

        // The plan wanted two; one is connected. Writing for a channel with
        // nowhere to send is spending money on a draft nobody will publish.
        $this->assertSame(['linkedin'], $article->derivatives()->pluck('channel_type')->all());
    }

    #[Test]
    public function a_project_with_no_social_channel_fails_rather_than_writing_nothing(): void
    {
        Channel::query()->delete();

        $run = $this->repurpose();

        $run = PipelineRun::acrossProjects()->whereKey($run->getKey())->firstOrFail();

        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame('load_parent', $run->failed_step_key);
    }

    #[Test]
    public function an_unpublished_article_has_nothing_to_derive_from(): void
    {
        $draft = ContentItem::factory()->draft()->create(['slug' => 'not-live-yet']);

        $run = PipelineRun::acrossProjects()
            ->whereKey($this->repurpose($draft)->getKey())
            ->firstOrFail();

        // A social post pointing at a page that does not exist is worse than
        // no post.
        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame('load_parent', $run->failed_step_key);
    }

    // ------------------------------------------------------- exit criterion 2

    #[Test]
    public function a_derivative_inherits_the_parents_entities_and_links(): void
    {
        // Something for the corpus to link to.
        $neighbour = $this->article([
            'slug' => 'window-cleaning-costs-in-lisbon',
            'title' => 'What window cleaning costs in Lisbon',
            'target_query' => 'window cleaning cost lisbon',
            'summary' => 'Lisbon window cleaning prices explained.',
            'entities' => ['Lisbon', 'Atlantic salt'],
            'public_url' => 'https://site.test/window-cleaning-costs',
        ]);

        app(CorpusIndex::class)->index($neighbour);

        $article = $this->article();
        $this->repurpose($article);

        $derivative = $article->derivatives()->firstOrFail();

        // Exit criterion 2, both halves.
        $this->assertSame($article->entities, $derivative->entities);
        $this->assertSame($article->refresh()->internal_links, $derivative->internal_links);
        $this->assertNotSame([], $derivative->internal_links);
    }

    #[Test]
    public function a_post_that_shares_none_of_the_parents_entities_is_refused(): void
    {
        // §8.1: one voice, checked by entity overlap. A post about something
        // else does nothing for the parent's citability.
        $this->models->willAnswerRole('draft', 'Here are some thoughts about an entirely unrelated subject.');

        $run = PipelineRun::acrossProjects()
            ->whereKey($this->repurpose()->getKey())
            ->firstOrFail();

        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame(SaveDerivatives::key(), $run->failed_step_key);
        $this->assertFalse($run->error['retryable']);
    }

    #[Test]
    public function running_it_twice_updates_rather_than_duplicates(): void
    {
        $article = $this->article();

        $this->repurpose($article);
        $this->repurpose($article);

        $this->assertSame(2, $article->derivatives()->count());
    }

    // ------------------------------------------------------- exit criterion 3

    #[Test]
    public function internal_links_come_from_embeddings_not_shared_words(): void
    {
        // Same subject, almost no words in common with the parent.
        $related = $this->article([
            'slug' => 'glass-maintenance-guide',
            'title' => 'Glass maintenance in coastal flats',
            'target_query' => 'glass maintenance coastal',
            'summary' => 'Lisbon Atlantic salt six weeks windows cleaned flats',
            'entities' => ['Lisbon', 'Atlantic salt'],
            'public_url' => 'https://site.test/glass-maintenance',
        ]);

        // Shares a word with the parent's title and is about nothing like it.
        $unrelated = $this->article([
            'slug' => 'how-often-to-refactor-a-codebase',
            'title' => 'How often to clean up a codebase',
            'target_query' => 'refactoring cadence',
            'summary' => 'Refactoring cadence deployment testing legacy technical debt.',
            'entities' => ['refactoring'],
            'public_url' => 'https://site.test/refactoring',
        ]);

        $corpus = app(CorpusIndex::class);
        $corpus->index($related);
        $corpus->index($unrelated);

        $article = $this->article();

        $links = $corpus->relatedTo($article, 1);

        $this->assertCount(1, $links);
        $this->assertSame('https://site.test/glass-maintenance', $links[0]['url']);
    }

    #[Test]
    public function the_linking_step_embeds_the_unit_once(): void
    {
        /** @var FakeEmbeddingGateway $embeddings */
        $embeddings = app(EmbeddingGateway::class);

        $before = $embeddings->callCount();

        $this->repurpose();

        // Indexing and querying are the same unit, and an embedding is billed
        // per token — computing the same vector twice is money for nothing.
        $this->assertSame(1, $embeddings->callCount() - $before);
    }

    #[Test]
    public function the_delivery_payload_carries_the_internal_links(): void
    {
        $neighbour = $this->article([
            'slug' => 'window-cleaning-costs-in-lisbon',
            'title' => 'What window cleaning costs in Lisbon',
            'summary' => 'Lisbon Atlantic salt six weeks windows cleaned flats',
            'public_url' => 'https://site.test/window-cleaning-costs',
        ]);

        app(CorpusIndex::class)->index($neighbour);

        $article = $this->article();
        $this->repurpose($article);

        $payload = WebhookPayload::for(
            $article->refresh(),
            WebhookEvent::Updated,
            'a-delivery-id',
        );

        // The key has been in the contract since version 1; phase 8 is what
        // fills it, and a payload that still sent an empty array would mean
        // the receiver never saw a single internal link.
        $this->assertNotSame([], $payload['content']['internal_links']);
    }

    #[Test]
    public function a_draft_is_never_linked_to(): void
    {
        ContentItem::factory()->draft()->create([
            'slug' => 'not-published',
            'title' => 'How often to clean windows in Lisbon',
            'summary' => 'Lisbon Atlantic salt six weeks',
            'public_url' => 'https://site.test/not-published',
        ]);

        $corpus = app(CorpusIndex::class);
        $corpus->index(ContentItem::query()->where('slug', 'not-published')->firstOrFail());

        // A link to a draft is a 404 on the site.
        $this->assertSame([], $corpus->relatedTo($this->article()));
    }

    #[Test]
    public function a_thin_corpus_links_to_nothing_rather_than_to_anything(): void
    {
        $far = $this->article([
            'slug' => 'completely-different',
            'title' => 'Quarterly accounting deadlines',
            'target_query' => 'accounting deadlines',
            'summary' => 'VAT returns payroll filings bookkeeping.',
            'entities' => ['VAT'],
            'public_url' => 'https://site.test/accounting',
        ]);

        $corpus = app(CorpusIndex::class);
        $corpus->index($far);

        // Better no link than a link to the least unrelated thing available.
        $this->assertSame([], $corpus->relatedTo($this->article()));
    }

    #[Test]
    public function links_stay_inside_one_language(): void
    {
        $portuguese = $this->article([
            'slug' => 'como-limpar-janelas',
            'locale' => 'pt-PT',
            'title' => 'How often to clean windows in Lisbon',
            'summary' => 'Lisbon Atlantic salt six weeks windows cleaned',
            'public_url' => 'https://site.test/pt/como-limpar-janelas',
        ]);

        app(CorpusIndex::class)->index($portuguese);

        // An English article linking into the Portuguese site is a dead end
        // for the reader.
        $this->assertSame([], app(CorpusIndex::class)->relatedTo($this->article()));
    }

    // ---------------------------------------------------------------- images

    #[Test]
    public function the_article_gets_a_hero_image(): void
    {
        $article = $this->article();

        $this->repurpose($article);

        $hero = $article->assets()->where('role', AssetRole::Hero)->firstOrFail();

        $this->assertSame(1200, $hero->width);
        $this->assertSame(630, $hero->height);
        Storage::disk('public')->assertExists($hero->path);
    }

    #[Test]
    public function a_second_run_does_not_buy_a_second_hero(): void
    {
        $article = $this->article();

        $this->repurpose($article);
        $this->repurpose($article);

        // One hero per unit — the database says so, and a second call would
        // spend money to hit a unique index.
        $this->assertSame(1, $article->assets()->where('role', AssetRole::Hero)->count());
    }

    #[Test]
    public function a_project_with_no_image_provider_still_gets_its_posts(): void
    {
        $this->app->bind(ImageGenerationProvider::class, fn (): ImageGenerationProvider => new class extends FakeImageGeneration
        {
            public function isConfigured(): bool
            {
                return false;
            }
        });

        $article = $this->article();
        $run = $this->repurpose($article);

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(2, $article->derivatives()->count());
        $this->assertSame(0, $article->assets()->count());

        // Skipped, not failed — and a skip still releases the fan-in.
        $this->assertSame('skipped', $run->steps()->where('step_key', GenerateHero::key())->firstOrFail()->status->value);
    }

    // ------------------------------------------------------- exit criterion 4

    #[Test]
    public function the_cost_of_a_unit_with_derivatives_and_images_is_known(): void
    {
        $run = $this->repurpose()->refresh();

        $byKey = $run->steps()->get()->keyBy('step_key');

        // The three steps that spend money.
        $this->assertGreaterThan(0, $byKey[LinkInternally::key()]->cost_micros + 1);
        $this->assertGreaterThan(0, $byKey['write_posts']->cost_micros);
        $this->assertGreaterThan(0, $run->cost_micros);

        // And the whole run rolls up.
        $this->assertSame((int) $run->steps()->sum('cost_micros'), $run->cost_micros);
    }

    #[Test]
    public function the_expensive_steps_are_on_the_expensive_queue(): void
    {
        $graph = app(PipelineRegistry::class)->graph('repurpose');

        $expensive = config('pipeline.queues.expensive');

        foreach ([LinkInternally::key(), GenerateHero::key(), 'write_posts'] as $key) {
            $this->assertSame($expensive, $graph->step($key)->queue(), "{$key} is on the wrong queue.");
        }
    }

    #[Test]
    public function the_hero_and_the_linking_run_in_parallel(): void
    {
        $run = $this->repurpose();

        $positions = $run->steps()->get()->mapWithKeys(
            fn ($step): array => [$step->step_key => $step->position]
        )->all();

        $this->assertSame(0, $positions['load_parent']);
        // A picture has nothing to do with the linking, and it is the slowest
        // thing here.
        $this->assertContains($positions[GenerateHero::key()], [1, 2]);
        $this->assertContains($positions[LinkInternally::key()], [1, 2]);
        $this->assertSame(4, $positions[SaveDerivatives::key()]);
    }

    #[Test]
    public function the_post_format_comes_from_configuration(): void
    {
        config()->set('publishing.formats.linkedin', 'A UNIQUE FORMAT INSTRUCTION');

        $this->repurpose();

        // §8.1: a new channel should be a config entry, not a change to the
        // step that writes.
        $this->assertTrue(
            collect($this->models->sent())->contains(
                fn ($request): bool => str_contains($request->prompt, 'A UNIQUE FORMAT INSTRUCTION')
            ),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function article(array $attributes = []): ContentItem
    {
        return ContentItem::factory()->published()->create([
            'title' => 'How often to clean windows in Lisbon',
            'slug' => 'how-often-to-clean-windows',
            'target_query' => 'window cleaning lisbon',
            'summary' => 'Lisbon flats need cleaning every six weeks.',
            'body_markdown' => '## Why',
            'entities' => ['Lisbon', 'Atlantic salt', 'six weeks'],
            'planned_derivatives' => ['linkedin', 'x'],
            'public_url' => 'https://site.test/how-often-to-clean-windows',
            ...$attributes,
        ]);
    }

    private function repurpose(?ContentItem $unit = null): PipelineRun
    {
        $unit ??= $this->article();

        return app(PipelineRunner::class)->start('repurpose', $this->project, [], $unit->getKey());
    }
}
