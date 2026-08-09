<?php

declare(strict_types=1);

namespace App\Support\Corpus;

use App\Models\Project;
use App\Models\SitePage;
use App\Support\Http\PublicHttpClient;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What the project's site already publishes.
 *
 * Two passes, because they cost differently. The sitemap is one request and
 * gives every URL, a guessed title and often a `<lastmod>`. Reading a page is a
 * request each, and is only worth doing for the ones that look like articles —
 * a topic somebody has already written about is the thing the planner has to
 * avoid repeating, and a contact form is not one.
 */
class SiteLibrary
{
    /** Enough for a small business site; a large one does not need all of it. */
    private const int MAX_PAGES = 500;

    /** Pages actually fetched per refresh. A crawl is a different product. */
    private const int MAX_READS = 60;

    private const int REFRESH_AFTER_DAYS = 7;

    public function __construct(
        private readonly CurrentProject $current,
        private readonly PublicHttpClient $http,
        private readonly PublicHttpTarget $targets,
    ) {}

    /**
     * Refresh the library if it is stale, and return what is in it.
     *
     * @return list<SitePage>
     */
    public function for(Project $project): array
    {
        return $this->current->run($project, function () use ($project): array {
            if ($this->isStale($project)) {
                $this->rebuild($project);
            }

            return array_values(SitePage::query()->orderBy('url')->get()->all());
        });
    }

    /**
     * The pages most worth linking to from a piece about `$query`.
     *
     * Term overlap rather than embeddings: this is the linking question, where
     * a shared word is a good enough signal and a few hundred embeddings per
     * project would not buy much. The *has this been covered* question is a
     * different one and is answered by meaning — see {@see TopicLibrary}.
     *
     * @param  list<SitePage>  $pages
     * @return list<SitePage>
     */
    public function relevantTo(array $pages, string $query, ?string $locale = null, int $limit = 5): array
    {
        $pages = $this->inLocale($pages, $locale);
        $terms = $this->terms($query);

        if ($terms === []) {
            return array_slice($pages, 0, $limit);
        }

        $scored = [];

        foreach ($pages as $page) {
            $shared = count(array_intersect($terms, $this->terms($page->title.' '.$page->url)));

            if ($shared > 0) {
                $scored[] = ['score' => $shared, 'page' => $page];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: strcmp($a['page']->url, $b['page']->url));

        return array_map(
            static fn (array $row): SitePage => $row['page'],
            array_slice($scored, 0, $limit),
        );
    }

    /**
     * Pages in the language this article is written in.
     *
     * A multilingual site puts the language in the path — /en/services,
     * /pt/services — and linking an English article to the Portuguese page
     * sends a reader somewhere they cannot read. Only applied when the sitemap
     * actually looks language-prefixed: on a single-language site every page is
     * the right one.
     *
     * @param  list<SitePage>  $pages
     * @return list<SitePage>
     */
    public function inLocale(array $pages, ?string $locale): array
    {
        if ($locale === null || $locale === '') {
            return $pages;
        }

        // `pt-PT` addresses a /pt/ path.
        $language = mb_strtolower(Str::before($locale, '-'));

        $matching = array_values(array_filter(
            $pages,
            static fn (SitePage $page): bool => str_contains(
                mb_strtolower((string) parse_url($page->url, PHP_URL_PATH)).'/',
                "/{$language}/",
            ),
        ));

        // Nothing matched, so this is not a language-prefixed sitemap and every
        // page is a candidate.
        return $matching === [] ? $pages : $matching;
    }

    /**
     * Read the sitemap, then read the articles it names.
     *
     * Wrapped in the project's tenant, because SitePage is scoped and writing
     * one without a current project throws outright. Taking a project as an
     * argument and then relying on the caller to have set it as the tenant is a
     * trap — the same one {@see TopicLibrary::index()} fell into.
     */
    public function refresh(Project $project): void
    {
        $this->current->run($project, fn () => $this->rebuild($project));
    }

    private function rebuild(Project $project): void
    {
        $sitemap = $project->sitemap_url;

        if ($sitemap === null || $sitemap === '') {
            return;
        }

        $entries = $this->fromSitemap($sitemap);

        if ($entries === []) {
            // Keep whatever we had. A sitemap that is briefly unreachable is
            // not a site that lost all its pages.
            return;
        }

        // Loaded once. Asking per URL turned a 244-page sitemap into 244
        // existence checks before a single row was written.
        $alreadyRead = SitePage::query()
            ->whereNotNull('read_at')
            ->pluck('url')
            ->flip();

        foreach ($entries as $entry) {
            SitePage::query()->updateOrCreate(
                ['url' => $entry['url']],
                [
                    // The title only where the page has not been read: a real
                    // title beats a slug, and a refresh must not overwrite one.
                    ...($alreadyRead->has($entry['url']) ? [] : ['title' => $entry['title']]),
                    'published_at' => $entry['published_at'],
                    'is_article' => $entry['is_article'],
                ],
            );
        }

        $this->readArticles($sitemap);
    }

    /**
     * Path segments that mark a page as something somebody wrote.
     *
     * A guess about another site's URL scheme, and a site whose posts live at
     * /2026/03/some-title has none of these — so it is config rather than a
     * constant, and an operator whose blog is at /stories can say so without a
     * deploy.
     *
     * @return list<string>
     */
    private function articleMarkers(): array
    {
        /** @var list<string> $markers */
        $markers = (array) config('research.article_path_markers', []);

        return $markers === [] ? ['journal', 'blog', 'news', 'post', 'posts', 'article', 'articles'] : $markers;
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            // Two-letter words are noise in every language this runs in.
            static fn (string $word): bool => mb_strlen($word) > 2,
        )));
    }

    /**
     * Fetch the article pages we have not read, for their real title and
     * description.
     *
     * Bounded per refresh: sixty requests to somebody else's site is polite,
     * six hundred is a crawl. What is missed this week is picked up next week.
     */
    private function readArticles(string $siteOrigin): void
    {
        $unread = SitePage::query()
            ->articles()
            ->whereNull('read_at')
            ->orderBy('url')
            ->limit(self::MAX_READS)
            ->get();

        foreach ($unread as $page) {
            $read = $this->read($page->url, $siteOrigin);

            $page->forceFill([
                ...$read,
                // Stamped whether or not the read worked, so a page that 404s
                // is not fetched again on every refresh forever.
                'read_at' => now(),
            ])->save();
        }
    }

    /**
     * @return array{title?: string, description?: string}
     */
    private function read(string $url, string $siteOrigin): array
    {
        try {
            $response = $this->http->request(
                'GET',
                $url,
                ['User-Agent' => 'ContentEngine/1.0 (+library)'],
                15,
                3,
                $siteOrigin,
            )->response;
        } catch (ConnectionException|UnsafePublicUrl $e) {
            Log::info('A site page could not be read', ['url' => $url, 'reason' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $html = $response->body();

        $read = [];

        $title = $this->tag($html, 'title') ?: $this->meta($html, 'og:title', 'property');

        if ($title !== '') {
            // Sites append their own name to every title. The article is the
            // part before the separator.
            $read['title'] = Str::of($title)
                ->before(' | ')
                ->before(' – ')
                ->before(' — ')
                ->squish()
                ->limit(200, '')
                ->toString();
        }

        $description = $this->meta($html, 'description') ?: $this->meta($html, 'og:description', 'property');

        if ($description !== '') {
            $read['description'] = Str::limit(Str::squish($description), 500, '');
        }

        return $read;
    }

    /**
     * @return list<array{url: string, title: string, published_at: Carbon|null, is_article: bool}>
     */
    private function fromSitemap(string $sitemap): array
    {
        try {
            $response = $this->http->request(
                'GET',
                $sitemap,
                ['User-Agent' => 'ContentEngine/1.0 (+library)'],
                20,
                3,
                $sitemap,
            )->response;
        } catch (ConnectionException|UnsafePublicUrl $e) {
            Log::warning('A sitemap could not be read', ['sitemap' => $sitemap, 'reason' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('A sitemap answered an error', ['sitemap' => $sitemap, 'status' => $response->status()]);

            return [];
        }

        // Matched per <url> block so a <lastmod> stays with the <loc> it
        // belongs to. A sitemap index, a gzipped body and a plain list are all
        // things a site serves under this name; this handles the common one and
        // returns nothing rather than guessing at the others.
        preg_match_all('/<url>(.*?)<\/url>/is', $response->body(), $blocks);

        $entries = [];

        foreach ($blocks[1] as $block) {
            if (count($entries) >= self::MAX_PAGES) {
                break;
            }

            if (preg_match('/<loc>\s*([^<\s]+)\s*<\/loc>/i', $block, $loc) !== 1) {
                continue;
            }

            $url = $loc[1];

            try {
                $this->targets->validate($url, $sitemap);
            } catch (UnsafePublicUrl $e) {
                Log::notice('A sitemap entry was outside the configured public site', [
                    'sitemap' => $sitemap,
                    'url' => $url,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            // A nested sitemap is a sitemap, not a page.
            if (str_ends_with(strtolower($url), '.xml')) {
                continue;
            }

            $lastmod = preg_match('/<lastmod>\s*([^<\s]+)\s*<\/lastmod>/i', $block, $mod) === 1
                ? $this->date($mod[1])
                : null;

            $entries[] = [
                'url' => $url,
                'title' => $this->titleFrom($url),
                'published_at' => $lastmod,
                'is_article' => $this->looksLikeArticle($url),
            ];
        }

        return $entries;
    }

    private function looksLikeArticle(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));
        $segments = array_filter(explode('/', $path));

        foreach ($segments as $segment) {
            if (in_array($segment, $this->articleMarkers(), true)) {
                // The section index itself is not an article.
                return $segment !== end($segments);
            }
        }

        return false;
    }

    private function date(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** A readable name from the URL, because a sitemap carries no titles. */
    private function titleFrom(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return 'Home';
        }

        return Str::of(Str::afterLast($path, '/'))
            ->replaceMatches('/\.(html?|php|aspx?)$/i', '')
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->toString();
    }

    private function isStale(Project $project): bool
    {
        $newest = SitePage::query()->max('updated_at');

        return $newest === null
            || Carbon::parse((string) $newest)->diffInDays(now()) >= self::REFRESH_AFTER_DAYS;
    }

    private function tag(string $html, string $tag): string
    {
        return preg_match('/<'.$tag.'[^>]*>(.*?)<\/'.$tag.'>/is', $html, $m) === 1
            ? trim(html_entity_decode(strip_tags($m[1])))
            : '';
    }

    private function meta(string $html, string $name, string $attribute = 'name'): string
    {
        $pattern = '/<meta[^>]+'.$attribute.'=["\']'.preg_quote($name, '/').'["\'][^>]*content=["\'](.*?)["\']/is';

        if (preg_match($pattern, $html, $m) === 1) {
            return trim(html_entity_decode($m[1]));
        }

        // Attribute order is not fixed, so the reverse spelling has to be tried.
        $reversed = '/<meta[^>]+content=["\'](.*?)["\'][^>]*'.$attribute.'=["\']'.preg_quote($name, '/').'["\']/is';

        return preg_match($reversed, $html, $m) === 1 ? trim(html_entity_decode($m[1])) : '';
    }
}
