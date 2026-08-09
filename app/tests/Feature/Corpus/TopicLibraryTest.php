<?php

declare(strict_types=1);

namespace Tests\Feature\Corpus;

use App\Ai\Contracts\EmbeddingGateway;
use App\Ai\FakeEmbeddingGateway;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SitePage;
use App\Support\Corpus\CorpusIndex;
use App\Support\Corpus\TopicLibrary;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The duplicate check, by meaning.
 *
 * The case this exists for: the site publishes "Limpeza De Carpetes Preco" and
 * the planner is about to schedule "Carpet Cleaning Lisbon". They share no
 * word. A string comparison finds nothing and the same article gets written
 * twice.
 */
final class TopicLibraryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_article_the_site_already_has_is_found(): void
    {
        [$project, $embeddings] = $this->library();

        $this->page($project, 'Limpeza de carpetes: preço e o que esperar');

        // Same subject in another language: the vectors land on top of each
        // other even though the words do not.
        $embeddings->willEmbed('Limpeza de carpetes: preço e o que esperar', [1.0, 0.0, 0.0]);
        $embeddings->willEmbed('carpet cleaning lisbon', [0.99, 0.14, 0.0]);

        app(TopicLibrary::class)->index($project);

        $covered = app(TopicLibrary::class)->alreadyCovered($project, 'carpet cleaning lisbon');

        $this->assertNotNull($covered);
        $this->assertSame('Limpeza de carpetes: preço e o que esperar', $covered['title']);
    }

    #[Test]
    public function a_different_subject_is_left_alone(): void
    {
        [$project, $embeddings] = $this->library();

        $this->page($project, 'Limpeza de carpetes: preço e o que esperar');

        $embeddings->willEmbed('Limpeza de carpetes: preço e o que esperar', [1.0, 0.0, 0.0]);
        $embeddings->willEmbed('how to clean marble floors', [0.0, 1.0, 0.0]);

        app(TopicLibrary::class)->index($project);

        // Nothing to do with carpets. Blocking this would be worse than not
        // checking: the month would quietly lose articles nobody duplicated.
        $this->assertNull(app(TopicLibrary::class)->alreadyCovered($project, 'how to clean marble floors'));
    }

    #[Test]
    public function pages_that_are_not_articles_are_never_indexed(): void
    {
        [$project, $embeddings] = $this->library();

        app(CurrentProject::class)->run($project, function (): void {
            SitePage::factory()->notAnArticle()->create(['title' => 'Cleaning services']);
        });

        $embeddings->willEmbed('Cleaning services', [1.0, 0.0, 0.0]);

        // A services page is not a topic somebody covered, and paying to
        // compare an article against a contact form is money for nothing.
        $this->assertSame(0, app(TopicLibrary::class)->index($project));
    }

    #[Test]
    public function indexing_twice_does_not_pay_twice(): void
    {
        [$project, $embeddings] = $this->library();

        $this->page($project, 'Limpeza de carpetes');
        $embeddings->willEmbed('Limpeza de carpetes', [1.0, 0.0, 0.0]);

        $this->assertSame(1, app(TopicLibrary::class)->index($project));
        $this->assertSame(0, app(TopicLibrary::class)->index($project));
    }

    #[Test]
    public function another_projects_articles_are_not_consulted(): void
    {
        [$project, $embeddings] = $this->library();

        $theirs = Project::factory()->create();
        $this->page($theirs, 'Limpeza de carpetes');

        $embeddings->willEmbed('Limpeza de carpetes', [1.0, 0.0, 0.0]);
        $embeddings->willEmbed('carpet cleaning', [1.0, 0.0, 0.0]);

        app(TopicLibrary::class)->index($theirs);

        $this->assertNull(app(TopicLibrary::class)->alreadyCovered($project, 'carpet cleaning'));
    }

    #[Test]
    public function the_topic_vector_never_overwrites_the_linking_one(): void
    {
        [$project, $embeddings] = $this->library();

        $unit = app(CurrentProject::class)->run(
            $project,
            fn (): ContentItem => ContentItem::factory()->published()->create([
                'target_query' => 'carpet cleaning lisbon',
            ]),
        );

        // The corpus writes the article's body here so one finished piece can
        // find another to link to.
        app(CorpusIndex::class)->index($unit);

        $body = $this->column($unit, 'embedding');

        app(TopicLibrary::class)->rememberVector($unit, 'carpet cleaning lisbon');

        // A query and a body are different representations. Writing one into
        // the other's column silently breaks internal linking, and comparing
        // across them puts two texts far apart whatever the subject.
        $this->assertSame($body, $this->column($unit, 'embedding'));
        $this->assertNotNull($this->column($unit, 'topic_embedding'));
    }

    private function column(ContentItem $unit, string $column): ?string
    {
        /** @var list<object{value: string|null}> $rows */
        $rows = DB::select(
            "select {$column}::text as value from content_items where id = ?",
            [$unit->getKey()],
        );

        return $rows[0]->value ?? null;
    }

    /**
     * @return array{Project, FakeEmbeddingGateway}
     */
    private function library(): array
    {
        $project = Project::factory()->create();

        /** @var FakeEmbeddingGateway $embeddings */
        $embeddings = app(EmbeddingGateway::class);

        return [$project, $embeddings];
    }

    private function page(Project $project, string $title): SitePage
    {
        return app(CurrentProject::class)->run(
            $project,
            fn (): SitePage => SitePage::factory()->create(['title' => $title, 'description' => null]),
        );
    }
}
