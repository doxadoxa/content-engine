<?php

declare(strict_types=1);

namespace Tests\Feature\Corpus;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelRequest;
use App\Ai\UnmeteredSession;
use App\ContentStudio\ContentStudioAssistant;
use App\Enums\SitePageKind;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SitePage;
use App\Support\Corpus\SiteLibrary;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which pages the harvest reads, and which it reads again.
 *
 * The reading itself is faked at the HTTP boundary — what is under test is the
 * queue, because every defect found in review was a defect in *what gets
 * chosen*: pages read once and never again, translations admitted after the
 * first pass, and a corpus that is empty when the first month is planned.
 */
final class SiteLibraryHarvestTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'sitemap_url' => 'https://example.test/sitemap.xml',
            'default_locale' => 'en',
        ]);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('<html><body><p>From 18 EUR an hour.</p></body></html>')]);
    }

    /**
     * A price read once is a price the engine repeats after it changes.
     *
     * Only commercial pages come back: an editorial body is never stored, so
     * there is nothing about one to go stale.
     */
    #[Test]
    public function a_commercial_page_is_read_again_when_the_sitemap_says_it_changed(): void
    {
        $changed = $this->page('https://example.test/en/services', SitePageKind::Commercial, [
            'read_at' => Carbon::parse('2026-08-01'),
            'published_at' => Carbon::parse('2026-08-15'),
        ]);

        $unchanged = $this->page('https://example.test/en/about', SitePageKind::Commercial, [
            'read_at' => Carbon::parse('2026-08-15'),
            'published_at' => Carbon::parse('2026-08-01'),
        ]);

        $editorial = $this->page('https://example.test/en/journal/tips', SitePageKind::Editorial, [
            'read_at' => Carbon::parse('2026-08-01'),
            'published_at' => Carbon::parse('2026-08-15'),
        ]);

        $this->harvest('commercial');

        $this->assertTrue($changed->fresh()->read_at->isToday(), 'The page whose lastmod moved is re-read.');
        $this->assertFalse($unchanged->fresh()->read_at->isToday());
        $this->assertFalse($editorial->fresh()->read_at->isToday(), 'Nothing about an editorial page is stored.');
    }

    #[Test]
    public function a_commercial_page_is_read_again_once_it_is_old_enough(): void
    {
        $old = $this->page('https://example.test/en/prices', SitePageKind::Commercial, [
            'read_at' => now()->subDays(40),
            'published_at' => now()->subDays(90),
        ]);

        $recent = $this->page('https://example.test/en/areas', SitePageKind::Commercial, [
            'read_at' => now()->subDays(3),
            'published_at' => now()->subDays(90),
        ]);

        $this->harvest('commercial');

        $this->assertTrue($old->fresh()->read_at->isToday());
        $this->assertFalse($recent->fresh()->read_at->isToday());
    }

    /**
     * The second harvest of a multilingual site must not admit its translations.
     *
     * `inLocale()` falls back to "every page" when nothing matches, which is
     * right for a monolingual site and catastrophic when it is asked about the
     * unread remainder of a multilingual one — by then the remainder is exactly
     * the pages that are not the default locale, so the fallback fires and the
     * offer arrives in four languages.
     */
    #[Test]
    public function translations_are_still_refused_once_the_default_locale_is_done(): void
    {
        $this->page('https://example.test/en/services', SitePageKind::Commercial, [
            'read_at' => now(),
            'published_at' => now()->subYear(),
        ]);

        foreach (['pt', 'ru', 'uk'] as $locale) {
            $this->page("https://example.test/{$locale}/services", null);
        }

        $this->harvest('commercial');

        // Inside the tenant, or the scope fails closed and both counts are zero
        // — which is a green test that asserts nothing. See ProjectScope.
        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(
                0,
                SitePage::query()->whereNotNull('page_kind')->where('url', 'like', '%/pt/%')->count(),
                'A Portuguese page was classified, so the offer will reach the planner twice.',
            );
            $this->assertSame(
                3,
                SitePage::query()->whereNull('page_kind')->count(),
                'All three translations stay unread.',
            );
        });
    }

    /** A page this engine published is not the business speaking. */
    #[Test]
    public function our_own_published_pages_are_never_read(): void
    {
        ContentItem::factory()->for($this->project)->create([
            'public_url' => 'https://example.test/en/journal/ours',
        ]);

        $ours = $this->page('https://example.test/en/journal/ours', null);
        $theirs = $this->page('https://example.test/en/services', null);

        $this->harvest('commercial');

        $this->assertNull($ours->fresh()->page_kind);
        $this->assertSame(SitePageKind::Commercial, $theirs->fresh()->page_kind);
    }

    /**
     * A month planned before research has ever run still has facts.
     *
     * `ProjectLaunch::begin()` dispatches research and the first Studio
     * proposal at the same time, so on a new project the proposal races the
     * harvest and generally wins — and a month planned without facts is not
     * re-planned when they arrive. Every project migrated into this feature
     * starts with the same empty corpus for the same reason.
     */
    #[Test]
    public function a_proposal_that_finds_no_corpus_reads_the_site_itself(): void
    {
        $this->page('https://example.test/en/services', null);

        $fake = (new FakeModelGateway)->willAnswerUsing(
            static function (ModelRequest $request): ?string {
                if ($request->role !== 'utility') {
                    return null;
                }

                return (string) json_encode(['pages' => [['page' => 1, 'kind' => 'commercial']]]);
            },
        );

        $this->app->instance(ModelGateway::class, $fake);

        app(CurrentProject::class)->run($this->project, function (): void {
            $assistant = app(ContentStudioAssistant::class);
            $ensure = new \ReflectionMethod($assistant, 'ensureFacts');
            $ensure->setAccessible(true);
            $ensure->invoke($assistant, $this->project, app(UnmeteredSession::class));

            $this->assertSame(1, SitePage::query()->commercial()->count());
        });
    }

    /** And it does not re-read a site research has already been through. */
    #[Test]
    public function a_proposal_that_finds_a_corpus_leaves_it_alone(): void
    {
        $page = $this->page('https://example.test/en/services', SitePageKind::Commercial, [
            'read_at' => now()->subDay(),
            'published_at' => now()->subYear(),
        ]);

        $fake = (new FakeModelGateway)->willAnswerUsing(static fn (): string => '{"pages":[]}');
        $this->app->instance(ModelGateway::class, $fake);

        app(CurrentProject::class)->run($this->project, function () use ($fake): void {
            $assistant = app(ContentStudioAssistant::class);
            $ensure = new \ReflectionMethod($assistant, 'ensureFacts');
            $ensure->setAccessible(true);
            $ensure->invoke($assistant, $this->project, app(UnmeteredSession::class));

            $this->assertSame(0, $fake->callCount(), 'A filled corpus is research\'s to grow, not the proposal\'s.');
        });

        $this->assertFalse($page->fresh()->read_at->isToday());
    }

    /** @param  array<string, mixed>  $attributes */
    private function page(string $url, ?SitePageKind $kind, array $attributes = []): SitePage
    {
        return app(CurrentProject::class)->run($this->project, fn (): SitePage => SitePage::query()->create([
            'url' => $url,
            'title' => 'A page',
            'is_article' => str_contains($url, '/journal/'),
            'page_kind' => $kind,
            'body' => $kind === SitePageKind::Commercial ? 'Old text.' : null,
            ...$attributes,
        ]));
    }

    private function harvest(string $kind): int
    {
        $fake = (new FakeModelGateway)->willAnswerUsing(
            static function (ModelRequest $request) use ($kind): string {
                $pages = preg_match_all('/^PAGE (\d+)$/m', $request->prompt, $matches);

                return (string) json_encode([
                    'pages' => array_map(
                        static fn (string $n): array => ['page' => (int) $n, 'kind' => $kind],
                        $pages === 0 ? [] : $matches[1],
                    ),
                ]);
            },
        );

        $this->app->instance(ModelGateway::class, $fake);

        return app(SiteLibrary::class)->harvest($this->project, app(UnmeteredSession::class));
    }
}
