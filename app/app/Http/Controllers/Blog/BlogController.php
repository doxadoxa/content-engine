<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blog;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\BlogSlugRedirect;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/*
 * What readers and crawlers see of Avyo's blog.
 *
 * Blade rather than Inertia, and that is the whole point of this controller.
 * The marketing site renders without SSR, so its words exist only as JSON
 * until JavaScript runs — fine for a landing page, fatal for a blog whose job
 * is to be read by search engines and AI crawlers, most of which never run a
 * line of it. Everything here is in the HTML the server sends: the article,
 * its metadata, its structured data and its other languages.
 *
 * "Live" means published_at has passed (`BlogPost::scopeLive`). A post the
 * engine delivered ahead of its date is a 404 at its URL and absent from every
 * list until then, so a scheduled article cannot be found early by guessing.
 */
final class BlogController extends Controller
{
    /** What a listing shows. The bodies stay in the database. */
    private const LISTING_COLUMNS = [
        'id', 'locale_group_id', 'locale', 'slug', 'type', 'title', 'summary',
        'images', 'author', 'published_at', 'updated_at',
    ];

    private const FEED_SIZE = 20;

    /*
     * Schema types our BlogPosting fields can be merged into. Every one is a
     * CreativeWork that a page of prose is — headline, dates, author and
     * publisher mean the same thing on each. The engine also sends `ItemList`
     * for listicles and `Product` for product pages; a headline or an author
     * on either is not what those types describe, so they stand beside our
     * BlogPosting instead of absorbing it.
     */
    private const ARTICLE_TYPES = [
        'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report',
        'ScholarlyArticle', 'AnalysisNewsArticle', 'HowTo',
    ];

    public function index(): View
    {
        $posts = $this->live($this->defaultLocale())
            ->select(self::LISTING_COLUMNS)
            ->paginate((int) config('blog.per_page', 12))
            ->withPath('/blog');

        // A page past the end is a 404, not an empty 200: the latter is a soft
        // 404 to a crawler, and one it will keep coming back to.
        abort_if($posts->currentPage() > 1 && $posts->isEmpty(), 404);

        $page = $posts->currentPage();

        return view('blog.index', [
            'posts' => $posts,
            'title' => $page > 1 ? "Blog — page {$page} — Avyo" : 'Blog — Avyo',
            'description' => 'Practical guides to reaching customers through search and AI answers, researched, written and published by Avyo.',
            'canonical' => $this->indexUrl($page),
            'prev' => $page > 1 ? $this->indexUrl($page - 1) : null,
            'next' => $posts->hasMorePages() ? $this->indexUrl($page + 1) : null,
            'siteImage' => $this->absolute('/og.png'),
            'jsonLd' => [self::encode([
                '@context' => 'https://schema.org',
                '@type' => 'Blog',
                'name' => 'Avyo Blog',
                'url' => $this->indexUrl(1),
                'inLanguage' => $this->defaultLocale(),
                'publisher' => $this->publisher(),
            ])],
        ]);
    }

    public function show(string $slug): View|RedirectResponse
    {
        return $this->article($this->defaultLocale(), $slug);
    }

    /*
     * The default language has exactly one address, the short one. Its long
     * form is a permanent redirect rather than a second copy of the page, so
     * a link someone built by hand still lands and nothing ever has to decide
     * which of two URLs is canonical.
     *
     * Straight to the post's current address, in one hop: going via the
     * short form would chain a second 301 whenever the slug has since moved.
     */
    public function showLocalized(string $locale, string $slug): View|RedirectResponse
    {
        if ($locale === $this->defaultLocale()) {
            $post = $this->live($locale)->where('slug', $slug)->first();

            return $post === null
                ? $this->formerAddress($locale, $slug)
                : redirect()->away($post->publicUrl(), 301);
        }

        return $this->article($locale, $slug);
    }

    /*
     * RSS 2.0 of the newest posts in the blog's own language. `content:encoded`
     * carries the whole article: feed readers and the AI tools that ingest
     * feeds get the text, not a teaser that makes them come and scrape.
     */
    public function feed(): Response
    {
        $posts = $this->live($this->defaultLocale())->limit(self::FEED_SIZE)->get();

        return response()
            ->view('blog.feed', [
                'posts' => $posts,
                'self' => $this->absolute(route('blog.feed', absolute: false)),
                'home' => $this->indexUrl(1),
                'locale' => $this->defaultLocale(),
            ])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    private function article(string $locale, string $slug): View|RedirectResponse
    {
        $post = $this->live($locale)->where('slug', $slug)->first();

        if ($post === null) {
            return $this->formerAddress($locale, $slug);
        }

        $others = $post->siblings()->live()
            ->get(['id', 'locale_group_id', 'locale', 'slug'])
            ->sortBy('locale')
            ->values();

        $hero = $post->hero();

        if ($hero !== null) {
            $hero['url'] = $this->absolute((string) $hero['url']);
        }

        $description = $post->summary !== null && trim($post->summary) !== ''
            ? trim($post->summary)
            : Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($post->html))), 160);

        return view('blog.show', [
            'post' => $post,
            'hero' => $hero,
            'others' => $others,
            'hreflang' => BlogPost::hreflang([$post, ...$others]),
            'title' => "{$post->title} — Avyo",
            'description' => $description,
            'canonical' => $post->publicUrl(),
            'siteImage' => $this->absolute('/og.png'),
            'jsonLd' => $this->structuredData($post, $description, $hero),
        ]);
    }

    /*
     * A slug the post has since moved away from. Permanent, so links and the
     * search index follow it to the new address; a 404 if the post it points
     * at is not live, because redirecting to a page that 404s is worse than
     * saying so here.
     */
    private function formerAddress(string $locale, string $slug): RedirectResponse
    {
        $redirect = BlogSlugRedirect::query()->where('locale', $locale)->where('slug', $slug)->first();

        $post = $redirect === null ? null : BlogPost::query()->live()->whereKey($redirect->blog_post_id)->first();

        abort_if($post === null, 404);

        // Absolute from APP_URL, like the canonical: a redirect that kept the
        // request's host would carry a stray hostname along instead of
        // settling on the one address.
        return redirect()->away($post->publicUrl(), 301);
    }

    /**
     * JSON-LD blocks, already encoded for a `<script>` element.
     *
     * The engine's article schema knows what the article is — its type,
     * headline, author — but not where it lives or when it went up, because
     * the engine cannot know either until a receiver has answered. So when it
     * is an article its keys win and ours fill the gaps. When it is something
     * else (an ItemList, a Product) the page is still a blog post, so ours
     * goes out whole beside it. A `@graph` is a shape the engine chose
     * deliberately, and is passed through rather than guessed into.
     *
     * @param  array<string, mixed>|null  $hero
     * @return list<string>
     */
    private function structuredData(BlogPost $post, string $description, ?array $hero): array
    {
        $author = is_string($post->author['name'] ?? null) && $post->author['name'] !== ''
            ? array_filter([
                '@type' => 'Person',
                'name' => $post->author['name'],
                'jobTitle' => is_string($post->author['title'] ?? null) ? $post->author['title'] : null,
            ])
            : $this->publisher();

        $known = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $post->title,
            'description' => $description,
            'inLanguage' => $post->locale,
            'url' => $post->publicUrl(),
            'mainEntityOfPage' => $post->publicUrl(),
            'datePublished' => $post->published_at->toAtomString(),
            'dateModified' => $post->updated_at->toAtomString(),
            'image' => $hero['url'] ?? null,
            'author' => $author,
            'publisher' => $this->publisher(),
        ], static fn (mixed $value): bool => $value !== null);

        $article = $post->json_ld;

        $blocks = match (true) {
            $article === null || $article === [] => [$known],
            isset($article['@graph']) => [$article],
            $this->isArticle($article['@type'] ?? null) => [$article + $known],
            default => [$known, $article],
        };

        if ($post->faq_json_ld !== null && $post->faq_json_ld !== []) {
            $blocks[] = $post->faq_json_ld;
        }

        return array_map(self::encode(...), $blocks);
    }

    /** A `@type` of one of {@see ARTICLE_TYPES}, alone or in a list of types. */
    private function isArticle(mixed $type): bool
    {
        $types = is_array($type) ? $type : [$type];

        return array_intersect(array_filter($types, is_string(...)), self::ARTICLE_TYPES) !== [];
    }

    /**
     * JSON for inside `<script type="application/ld+json">`.
     *
     * The HEX flags are the security boundary. The schema carries text the
     * engine wrote — a headline, an FAQ answer — and `</script>` anywhere in it
     * would otherwise end the element and turn the rest into markup. With
     * `<`, `>` and `&` escaped as < and friends the data cannot close
     * the tag it sits in, and still decodes to the same JSON.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function encode(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /** @return array<string, mixed> */
    private function publisher(): array
    {
        return [
            '@type' => 'Organization',
            'name' => 'Avyo',
            'url' => $this->absolute('/'),
            'logo' => ['@type' => 'ImageObject', 'url' => $this->absolute('/apple-touch-icon.png')],
        ];
    }

    /** @return Builder<BlogPost> */
    private function live(string $locale): Builder
    {
        return BlogPost::query()->live()->where('locale', $locale);
    }

    private function defaultLocale(): string
    {
        return (string) config('blog.locale', 'en');
    }

    private function indexUrl(int $page): string
    {
        return $this->absolute($page > 1 ? "/blog?page={$page}" : '/blog');
    }

    /*
     * From APP_URL rather than the request, like `BlogPost::publicUrl()`: a
     * canonical that follows the Host header is one a proxy or a stray
     * hostname can rewrite.
     */
    private function absolute(string $url): string
    {
        if (preg_match('~^https?://~i', $url) === 1) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
