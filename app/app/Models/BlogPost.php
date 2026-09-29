<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Locale;

/**
 * One article on Avyo's own blog, in one language.
 *
 * Not tenant-scoped: the blog is public site content that a project happens
 * to write, not a project's data.
 *
 * Soft-deleted, and not for an undo button: a deleted post is a tombstone
 * that remembers when the engine asked for it gone, so a publish retried from
 * before that moment cannot bring it back (see BlogInbox). Every Eloquent
 * read skips tombstones by default, which is every read the blog makes.
 *
 * @property string $id
 * @property string $engine_id
 * @property string $locale_group_id
 * @property string $locale
 * @property string $slug
 * @property string|null $type
 * @property string $title
 * @property string|null $summary
 * @property string|null $markdown
 * @property string $html
 * @property list<array<string, mixed>> $images
 * @property array<string, mixed>|null $json_ld
 * @property array<string, mixed>|null $faq_json_ld
 * @property array<string, mixed>|null $author
 * @property list<mixed>|null $internal_links
 * @property Carbon $published_at
 * @property string|null $last_delivery_id
 * @property Carbon|null $source_sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    /**
     * A language tag the way the product accepts one (ProjectRequest), a
     * little looser in the subtags: the receiver should take whatever the
     * engine can send. Shared by the route and the webhook, so a post is
     * never stored under a locale its own URL would 404 on.
     */
    public const string LOCALE_PATTERN = '[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*';

    /** The only slug shape `/blog/{slug}` routes, for the same reason. */
    public const string SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /**
     * What the article *is*. A delivery that changes none of these is a
     * repeat of what is already here, and must not move `updated_at` — it is
     * the page's dateModified, the sitemap's lastmod and the feed's
     * lastBuildDate, and a replay is not an edit.
     */
    public const array CONTENT_FIELDS = [
        'locale_group_id', 'slug', 'type', 'title', 'summary', 'markdown', 'html',
        'images', 'json_ld', 'faq_json_ld', 'author', 'internal_links', 'published_at',
    ];

    protected $fillable = [
        'engine_id', 'locale_group_id', 'locale', 'slug', 'type', 'title', 'summary',
        'markdown', 'html', 'images', 'json_ld', 'faq_json_ld', 'author',
        'internal_links', 'published_at', 'last_delivery_id', 'source_sent_at',
    ];

    /**
     * Newest first, and nothing dated in the future: a scheduled date the
     * engine sent ahead of time is a promise, not a publication.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('published_at', '<=', now())->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * The other languages of the same article — what `hreflang` is built from.
     *
     * @return Builder<static>
     */
    public function siblings(): Builder
    {
        return static::query()
            ->where('locale_group_id', $this->locale_group_id)
            ->whereKeyNot($this->getKey());
    }

    /** Absolute, from APP_URL: this is what goes back to the engine as `public_url`. */
    public function publicUrl(): string
    {
        return rtrim((string) config('app.url'), '/').$this->path();
    }

    public function path(): string
    {
        return $this->locale === (string) config('blog.locale', 'en')
            ? "/blog/{$this->slug}"
            : "/blog/{$this->locale}/{$this->slug}";
    }

    /**
     * `hreflang` for one article across its languages, `x-default` included.
     *
     * Static, over versions the caller already holds, so the post page (one
     * article) and the sitemap (every article, grouped) build it the same way
     * without the sitemap running a query per row. A single language gets
     * nothing: an annotation that only points at itself says nothing.
     *
     * `x-default` is the blog's own language when the article has one, and
     * otherwise the first by locale — deterministic, so every version of the
     * article names the same page.
     *
     * @param  iterable<self>  $versions  every live version, this one included
     * @return list<array{hreflang: string, url: string}>
     */
    public static function hreflang(iterable $versions): array
    {
        $urls = [];

        foreach ($versions as $version) {
            $urls[$version->locale] = $version->publicUrl();
        }

        if (count($urls) < 2) {
            return [];
        }

        ksort($urls);

        $links = [];

        foreach ($urls as $locale => $url) {
            $links[] = ['hreflang' => (string) $locale, 'url' => $url];
        }

        $links[] = [
            'hreflang' => 'x-default',
            'url' => $urls[(string) config('blog.locale', 'en')] ?? $links[0]['url'],
        ];

        return $links;
    }

    /**
     * The language's own name for itself — "Deutsch", not "German" — because
     * a link to another language is read by the people who speak it.
     */
    public function languageName(): string
    {
        $name = class_exists(Locale::class) ? (string) Locale::getDisplayName($this->locale, $this->locale) : '';

        if ($name === '' || $name === $this->locale) {
            return strtoupper($this->locale);
        }

        // ICU lower-cases most of them ("español"), which reads as a typo in a list.
        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }

    /** @return array<string, mixed>|null */
    public function hero(): ?array
    {
        foreach ($this->images as $image) {
            if (($image['role'] ?? null) === 'hero' && is_string($image['url'] ?? null)) {
                return $image;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'images' => 'array',
            'json_ld' => 'array',
            'faq_json_ld' => 'array',
            'author' => 'array',
            'internal_links' => 'array',
            'published_at' => 'datetime',
            'source_sent_at' => 'datetime',
        ];
    }
}
