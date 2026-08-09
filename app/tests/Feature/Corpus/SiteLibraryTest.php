<?php

declare(strict_types=1);

namespace Tests\Feature\Corpus;

use App\Models\Project;
use App\Models\SitePage;
use App\Support\Corpus\SiteLibrary;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading what the site already publishes.
 *
 * The sitemap gives every URL for one request; the pages themselves are read
 * only where they look like something somebody wrote, because a topic already
 * covered is what the planner has to avoid repeating and a contact form is not
 * one.
 */
final class SiteLibraryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_sitemap_becomes_pages_and_articles_are_marked(): void
    {
        $project = $this->project();

        Http::fake([
            'example.com/sitemap.xml' => Http::response($this->sitemap()),
            'example.com/en/journal/*' => Http::response($this->article('Cleaning marble floors', 'How to keep marble looking new.')),
        ]);

        app(CurrentProject::class)->run($project, fn () => app(SiteLibrary::class)->refresh($project));

        $pages = $this->pages($project);

        $this->assertCount(4, $pages);

        // /en/journal/marble is an article; /en/services and the journal index
        // itself are not.
        $this->assertSame(
            ['https://example.com/en/journal/marble', 'https://example.com/en/journal/windows'],
            $pages->where('is_article', true)->pluck('url')->sort()->values()->all(),
        );
    }

    #[Test]
    public function an_article_gets_its_real_title_and_description(): void
    {
        $project = $this->project();

        Http::fake([
            'example.com/sitemap.xml' => Http::response($this->sitemap()),
            'example.com/*' => Http::response($this->article(
                'Cleaning marble floors | Cleaning Point',
                'How to keep marble looking new without etching it.',
            )),
        ]);

        app(CurrentProject::class)->run($project, fn () => app(SiteLibrary::class)->refresh($project));

        $page = $this->pages($project)->firstWhere('url', 'https://example.com/en/journal/marble');

        $this->assertNotNull($page);

        // The site's name is appended to every title; the article is the part
        // before the separator. A slug-derived guess would have said "Marble".
        $this->assertSame('Cleaning marble floors', $page->title);
        $this->assertSame('How to keep marble looking new without etching it.', $page->description);
        $this->assertNotNull($page->read_at);
    }

    #[Test]
    public function the_last_modified_date_is_kept(): void
    {
        $project = $this->project();

        Http::fake([
            'example.com/sitemap.xml' => Http::response($this->sitemap()),
            'example.com/*' => Http::response($this->article('A title', 'A description.')),
        ]);

        app(CurrentProject::class)->run($project, fn () => app(SiteLibrary::class)->refresh($project));

        $page = $this->pages($project)->firstWhere('url', 'https://example.com/en/journal/marble');

        // The recency rule needs a date, and this is the only one available
        // without fetching every page.
        $this->assertSame('2026-03-14', $page?->published_at?->toDateString());
    }

    #[Test]
    public function a_page_that_cannot_be_read_is_not_fetched_again_forever(): void
    {
        $project = $this->project();

        Http::fake([
            'example.com/sitemap.xml' => Http::response($this->sitemap()),
            'example.com/*' => Http::response('Gone', 404),
        ]);

        app(CurrentProject::class)->run($project, fn () => app(SiteLibrary::class)->refresh($project));

        $page = $this->pages($project)->firstWhere('url', 'https://example.com/en/journal/marble');

        // Stamped whether or not the read worked, so a 404 does not cost a
        // request on every refresh from now on.
        $this->assertNotNull($page?->read_at);
        $this->assertNull($page->description);
    }

    #[Test]
    public function a_sitemap_that_will_not_load_keeps_what_we_had(): void
    {
        $project = $this->project();

        app(CurrentProject::class)->run($project, function (): void {
            SitePage::factory()->create(['url' => 'https://example.com/en/journal/kept']);
        });

        Http::fake(['example.com/sitemap.xml' => Http::response('', 500)]);

        app(CurrentProject::class)->run($project, fn () => app(SiteLibrary::class)->refresh($project));

        // A sitemap that is briefly unreachable is not a site that lost all its
        // pages, and emptying the library would silently drop both linking and
        // the duplicate check.
        $this->assertCount(1, $this->pages($project));
    }

    #[Test]
    public function linking_prefers_pages_in_the_articles_language(): void
    {
        $project = $this->project();

        $pages = app(CurrentProject::class)->run($project, fn (): array => [
            SitePage::factory()->create(['url' => 'https://example.com/en/services/cleaning', 'title' => 'Cleaning services']),
            SitePage::factory()->create(['url' => 'https://example.com/pt/servicos/limpeza', 'title' => 'Cleaning services']),
        ]);

        $relevant = app(SiteLibrary::class)->relevantTo($pages, 'cleaning services lisbon', 'en');

        // An English article linking to the Portuguese page sends a reader
        // somewhere they cannot read it.
        $this->assertCount(1, $relevant);
        $this->assertSame('https://example.com/en/services/cleaning', $relevant[0]->url);
    }

    #[Test]
    public function a_site_that_names_its_section_differently_can_say_so(): void
    {
        // The marker list is a guess about somebody else's URL scheme. A site
        // publishing at /stories has none of the defaults, and would otherwise
        // have no articles at all to compare a planned topic against.
        config()->set('research.article_path_markers', ['stories']);

        $project = $this->project();

        Http::fake([
            'example.com/sitemap.xml' => Http::response(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <urlset>
                  <url><loc>https://example.com/stories/marble</loc></url>
                  <url><loc>https://example.com/en/journal/windows</loc></url>
                </urlset>
                XML),
            'example.com/*' => Http::response($this->article('A title', 'A description.')),
        ]);

        app(CurrentProject::class)->run($project, fn () => app(SiteLibrary::class)->refresh($project));

        $this->assertSame(
            ['https://example.com/stories/marble'],
            $this->pages($project)->where('is_article', true)->pluck('url')->all(),
        );
    }

    #[Test]
    public function sitemap_entries_cannot_escape_the_configured_site_origin(): void
    {
        $project = $this->project();

        Http::fake([
            'example.com/sitemap.xml' => Http::response(<<<'XML'
                <urlset>
                  <url><loc>https://example.com/en/journal/safe</loc></url>
                  <url><loc>http://127.0.0.1/internal</loc></url>
                  <url><loc>https://other.example.test/private</loc></url>
                </urlset>
                XML),
            'example.com/*' => Http::response($this->article('Safe', 'Public page.')),
        ]);

        app(SiteLibrary::class)->refresh($project);

        $this->assertSame(
            ['https://example.com/en/journal/safe'],
            $this->pages($project)->pluck('url')->all(),
        );
        Http::assertSentCount(2);
    }

    #[Test]
    public function the_library_sets_its_own_tenant(): void
    {
        // Every other test here wraps the call in CurrentProject::run, which
        // hides the thing this checks: called from a command or a job with no
        // tenant set — which is how the engine calls it — writing a page threw
        // outright, and reading returned nothing.
        $project = $this->project();

        Http::fake([
            'example.com/sitemap.xml' => Http::response($this->sitemap()),
            'example.com/*' => Http::response($this->article('A title', 'A description.')),
        ]);

        app(CurrentProject::class)->forget();

        app(SiteLibrary::class)->refresh($project);

        $this->assertCount(4, $this->pages($project));
    }

    private function project(): Project
    {
        return Project::factory()->create(['sitemap_url' => 'https://example.com/sitemap.xml']);
    }

    /**
     * @return Collection<int, SitePage>
     */
    private function pages(Project $project): Collection
    {
        return app(CurrentProject::class)->run($project, fn () => SitePage::query()->get());
    }

    private function sitemap(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset>
          <url><loc>https://example.com/en/journal/marble</loc><lastmod>2026-03-14</lastmod></url>
          <url><loc>https://example.com/en/journal/windows</loc></url>
          <url><loc>https://example.com/en/journal</loc></url>
          <url><loc>https://example.com/en/services</loc></url>
          <url><loc>https://example.com/sitemap-2.xml</loc></url>
        </urlset>
        XML;
    }

    private function article(string $title, string $description): string
    {
        return "<html><head><title>{$title}</title>"
            ."<meta name=\"description\" content=\"{$description}\"></head><body>x</body></html>";
    }
}
