<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Enums\LocaleMode;
use App\Models\ContentItem;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Research\Contracts\KeywordSource;
use App\Research\FakeKeywordSource;
use App\Research\KeywordIdea;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which language an article is written in, and who writes it.
 *
 * Three bugs shared one cause, and it was a single line stamping the project's
 * default locale onto every idea. A Portuguese keyword became an English
 * article; the English article kept the Portuguese search query as its title;
 * and its locale siblings inherited the Portuguese slug, so the English edition
 * of a Portuguese piece lived at `/en/journal/portas-brancas-sujam`.
 */
final class LocalePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeKeywordSource $keywords;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'market' => 'pt',
            'default_locale' => 'en',
            'locales' => ['pt-PT', 'ru'],
            'research_seeds' => ['limpeza'],
        ]);
        app(CurrentProject::class)->set($this->project);

        /** @var FakeKeywordSource $keywords */
        $keywords = app(KeywordSource::class);
        $this->keywords = $keywords;

        config()->set('queue.default', 'sync');
    }

    // ------------------------------------------------ the language of an idea

    #[Test]
    public function an_idea_takes_the_language_its_keyword_was_measured_in(): void
    {
        $this->keywords->willReturn('limpeza', [
            new KeywordIdea('empresa de limpeza lisboa', volume: 1000, difficulty: 20, language: 'pt'),
            new KeywordIdea('limpeza pos obra', volume: 720, difficulty: 20, language: 'pt'),
            new KeywordIdea('limpeza de sofas', volume: 300, difficulty: 20, language: 'pt'),
        ]);

        $this->research();

        // Not 'en'. The project publishes in English and the market only has
        // keyword data in Portuguese, so an English article about a Portuguese
        // query is one that cannot rank for the query it was planned from.
        $this->assertSame(['pt'], ContentItem::query()->distinct()->pluck('locale')->all());
    }

    #[Test]
    public function a_keyword_with_no_language_falls_back_to_the_project(): void
    {
        $this->keywords->willReturn('limpeza', [
            new KeywordIdea('a', volume: 1000, difficulty: 20),
            new KeywordIdea('b', volume: 900, difficulty: 20),
            new KeywordIdea('c', volume: 800, difficulty: 20),
        ]);

        $this->research();

        // A source with no opinion about language — Ahrefs has none — must not
        // leave the locale null and break every scope that reads it.
        $this->assertSame(['en'], ContentItem::query()->distinct()->pluck('locale')->all());
    }

    // ------------------------------------------------------------ who writes

    #[Test]
    public function a_translate_locale_gets_no_article_of_its_own(): void
    {
        $this->project->forceFill([
            'locale_modes' => ['en' => LocaleMode::Adapt->value, 'ru' => LocaleMode::Translate->value],
        ])->save();

        // Cleaning Point's receiver takes one article and fills the rest
        // itself. Writing all of them means four separate articles where the
        // webhook expects one, each then translated into four languages.
        $this->assertSame(['en', 'pt-PT'], $this->project->writtenLocales());
    }

    #[Test]
    public function an_unlisted_locale_is_adapted_rather_than_translated(): void
    {
        // The default is the position worth defaulting to: a machine
        // translation of prose that scored 100 is prose nobody has scored,
        // because every rule in the house style is a property of the writing.
        $this->assertSame(LocaleMode::Adapt, $this->project->localeMode('ru'));
        $this->assertSame(['en', 'pt-PT', 'ru'], $this->project->writtenLocales());
    }

    #[Test]
    public function a_project_that_translates_everything_writes_only_its_source(): void
    {
        $this->project->forceFill([
            'locale_modes' => [
                'en' => LocaleMode::Translate->value,
                'pt-PT' => LocaleMode::Translate->value,
                'ru' => LocaleMode::Translate->value,
            ],
        ])->save();

        $this->assertSame([], $this->project->writtenLocales());
    }

    private function research(): void
    {
        app(PipelineRunner::class)->start('research', $this->project);
    }
}
