<?php

declare(strict_types=1);

namespace App\Blog;

use App\Models\BlogPost;
use App\Models\BlogSlugRedirect;
use App\Support\Content\SafeMarkdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the blog does with a delivery once it is known to be genuine and new.
 *
 * Everything here runs inside the transaction that claimed the delivery id,
 * so a {@see RefusedDelivery} or a crash part-way through takes the claim
 * down with the write.
 */
final class BlogInbox
{
    /** The shape `/blog/{slug}` accepts; anything else would be a 404. */
    private const string SLUG = '/\A'.BlogPost::SLUG_PATTERN.'\z/';

    /** The shape `/blog/{locale}/{slug}` accepts, for the same reason. */
    private const string LOCALE = '/\A'.BlogPost::LOCALE_PATTERN.'\z/';

    /** The fields stored as JSON, compared by value rather than by key order. */
    private const array JSON_FIELDS = ['images', 'json_ld', 'faq_json_ld', 'author', 'internal_links'];

    public function __construct(private readonly SafeMarkdown $markdown) {}

    /**
     * `content.published` and `content.updated` alike: one row per unit per
     * language, so a re-publish replaces rather than adds.
     *
     * `$sentAt` is the envelope's `sent_at`: stamped when the engine queued the
     * delivery, kept verbatim on its retries and refreshed on an operator's
     * replay. A delivery stamped before the one already applied is a late
     * retry of an older version, and is answered without being written — so
     * a replay, being newer, always wins. The engine always sends it; one
     * that is missing or unreadable cannot be ordered, and is applied rather
     * than refused.
     *
     * @param  array<mixed>  $content
     */
    public function store(array $content, string $deliveryId, ?string $sentAt): StoredPost
    {
        $validator = Validator::make($content, [
            'id' => ['required', 'string', 'max:255'],
            'locale_group_id' => ['nullable', 'string', 'max:255'],
            'locale' => ['required', 'string', 'max:12', 'regex:'.self::LOCALE],
            'slug' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'max:32'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'markdown' => ['required', 'string'],
            'published_at' => ['nullable', 'string'],
            'images' => ['nullable', 'array'],
            'json_ld' => ['nullable', 'array'],
            'faq_json_ld' => ['nullable', 'array'],
            'author' => ['nullable', 'array'],
            'internal_links' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new RefusedDelivery($validator->errors()->first());
        }

        /** @var array{id: string, locale: string, title: string, markdown: string, locale_group_id?: string|null, slug?: string|null, type?: string|null, summary?: string|null, published_at?: string|null, images?: array<mixed>|null, json_ld?: array<string, mixed>|null, faq_json_ld?: array<string, mixed>|null, author?: array<string, mixed>|null, internal_links?: list<mixed>|null} $content */
        $slug = $this->slug($content['slug'] ?? null, $content['title'], $content['id']);
        $sent = $this->date($sentAt);

        // Locked, so two deliveries for the same unit settle one after the
        // other and the second sees the first's published_at and sent_at.
        // Tombstones included: a deleted post is still the row to compare to.
        $post = BlogPost::withTrashed()
            ->where('engine_id', $content['id'])
            ->where('locale', $content['locale'])
            ->lockForUpdate()
            ->first() ?? new BlogPost(['engine_id' => $content['id'], 'locale' => $content['locale']]);

        if ($post->exists && $this->isOlder($sent, $post)) {
            return new StoredPost($post, stale: true);
        }

        $this->claimSlug($content['locale'], $slug, $content['id']);

        $previousSlug = $post->exists ? $post->slug : null;
        $restoring = $post->exists && $post->trashed();

        $post->fill([
            'locale_group_id' => ($content['locale_group_id'] ?? null) ?: $content['id'],
            'slug' => $slug,
            'type' => ($content['type'] ?? null) ?: null,
            'title' => trim($content['title']),
            'summary' => ($content['summary'] ?? null) ?: null,
            // Kept as sent, heading and all: it is the record of what arrived.
            'markdown' => $content['markdown'],
            // Never the `html` the engine sent. This page is served on the
            // product's own origin, next to a logged-in session, so the body
            // is rendered here from Markdown with raw HTML and unsafe links
            // stripped — the sender being ourselves does not make the model
            // that wrote the article trustworthy.
            'html' => $this->markdown->render($this->withoutRepeatedTitle($content['markdown'], $content['title'])),
            'images' => $this->images($content['images'] ?? null),
            'json_ld' => $content['json_ld'] ?? null,
            'faq_json_ld' => $content['faq_json_ld'] ?? null,
            'author' => $content['author'] ?? null,
            'internal_links' => $content['internal_links'] ?? null,
            // An update without a date keeps the one the post already has; a
            // re-publish that moved every article to "today" would reshuffle
            // the index and the feed.
            'published_at' => $this->date($content['published_at'] ?? null) ?? $post->published_at ?? now(),
            'last_delivery_id' => $deliveryId,
            // Never cleared by a delivery that did not say: the stamp already
            // here is still the best answer to "is the next one older?".
            'source_sent_at' => $sent ?? $post->source_sent_at,
        ]);

        if ($restoring) {
            $post->deleted_at = null;
        }

        if (! $post->exists || $restoring || $this->contentChanged($post)) {
            $post->save();
        } else {
            // A replay, or the same revision reaching the blog twice: record
            // that it arrived, but it is not a modification of the article.
            BlogPost::withoutTimestamps(static fn (): bool => $post->save());
        }

        if ($previousSlug !== null && $previousSlug !== $slug) {
            BlogSlugRedirect::query()->updateOrCreate(
                ['locale' => $post->locale, 'slug' => $previousSlug],
                ['blog_post_id' => $post->getKey()],
            );
        }

        return new StoredPost($post, stale: false);
    }

    /**
     * `content.deleted`, as a tombstone. Already gone is the same answer: the
     * engine wants the post absent, and it is.
     *
     * A deletion stamped before the version already here is a late retry
     * from before a re-publish, and removes nothing. A deletion for a unit
     * the blog has never stored leaves no tombstone behind — there is no row
     * to put one on — so a publish retried after it would still land.
     *
     * Returns whether the deletion was acted on.
     *
     * @param  array<mixed>  $content
     */
    public function delete(array $content, string $deliveryId, ?string $sentAt): bool
    {
        $id = $content['id'] ?? null;
        $locale = $content['locale'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($locale) || $locale === '') {
            throw new RefusedDelivery('A deletion needs content.id and content.locale.');
        }

        $sent = $this->date($sentAt);

        $post = BlogPost::withTrashed()
            ->where('engine_id', $id)
            ->where('locale', $locale)
            ->lockForUpdate()
            ->first();

        if ($post === null) {
            return true;
        }

        if ($this->isOlder($sent, $post)) {
            return false;
        }

        // Stamped first and deleted second: `delete()` writes only its own
        // column, and the stamp is the half of the tombstone that matters.
        $post->forceFill([
            'last_delivery_id' => $deliveryId,
            'source_sent_at' => $sent ?? $post->source_sent_at,
        ]);
        BlogPost::withoutTimestamps(static fn (): bool => $post->save());

        if (! $post->trashed()) {
            $post->delete();
        }

        return true;
    }

    /**
     * Whether a delivery was queued before the one the row already reflects.
     * Equal is not older: two versions queued in the same second are settled
     * by arrival, the last one standing.
     */
    private function isOlder(?Carbon $sent, BlogPost $post): bool
    {
        return $sent !== null && $post->source_sent_at !== null && $sent->lt($post->source_sent_at);
    }

    /**
     * Make `$slug` free for `$engineId` in `$locale`, or refuse.
     *
     * A live post of another unit keeps it: 422 rather than 409, because the
     * engine counts a 409 as delivered and this is precisely the case an
     * operator has to hear about. A tombstone gives it up — it is a unit that
     * no longer owns any URL, and it goes for good rather than holding one.
     * An old address the slug redirects from gives it up too: a post that
     * really has the slug beats a pointer to where one used to be.
     *
     * Two different units racing for one free slug are not caught here: the
     * loser hits the unique index, answers 500, and its retry meets the
     * winner and is refused like any other clash.
     */
    private function claimSlug(string $locale, string $slug, string $engineId): void
    {
        $holders = BlogPost::withTrashed()
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->where('engine_id', '!=', $engineId)
            ->lockForUpdate()
            ->get();

        foreach ($holders as $holder) {
            if (! $holder->trashed()) {
                throw new RefusedDelivery("Another post in {$locale} already has the slug \"{$slug}\".");
            }
        }

        foreach ($holders as $holder) {
            $holder->forceDelete();
        }

        BlogSlugRedirect::query()->where('locale', $locale)->where('slug', $slug)->delete();
    }

    /**
     * Whether the filled model differs from the stored one in anything a
     * reader could see. JSON by value: jsonb hands keys back in its own
     * order, and Eloquent's dirty check would call that a change every time.
     */
    private function contentChanged(BlogPost $post): bool
    {
        foreach (BlogPost::CONTENT_FIELDS as $field) {
            if (! $post->isDirty($field)) {
                continue;
            }

            if (! in_array($field, self::JSON_FIELDS, true)) {
                return true;
            }

            $original = $post->getOriginal($field);

            if ($this->canonical($post->getAttribute($field)) !== $this->canonical($original)) {
                return true;
            }
        }

        return false;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The engine's slug, made to fit the route; the title's when that leaves
     * nothing; the unit's own id when neither does.
     *
     * The engine writes slugs in the article's own language, and the route
     * only takes ASCII — so "limpeza-de-janelas-ção" is transliterated here
     * rather than published at a URL nothing can reach. Scripts with no
     * transliteration (Japanese, Chinese, Korean) leave nothing at all, and
     * an unreadable URL is still better than an article that went nowhere.
     */
    private function slug(?string $sent, string $title, string $engineId): string
    {
        foreach ([(string) $sent, $title, strtolower($engineId)] as $candidate) {
            $slug = Str::slug($candidate);

            if (preg_match(self::SLUG, $slug) === 1 && strlen($slug) <= 255) {
                return $slug;
            }
        }

        throw new RefusedDelivery('The post has no usable slug: neither its slug, its title nor its id leaves one.');
    }

    /**
     * The body without the headline, when the headline is its first line.
     *
     * The engine's writer opens every article with `# Title`, and the title
     * the engine sends is lifted off that very heading (FinaliseDraft). The
     * page renders the title as its own h1, so keeping the heading would show
     * it twice and give the page two h1s. Only a heading that repeats the
     * title goes: a different opening heading is the writer's, not ours.
     */
    private function withoutRepeatedTitle(string $markdown, string $title): string
    {
        if (preg_match('/\A\s*#[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*(?:\R|\z)/u', $markdown, $heading) !== 1) {
            return $markdown;
        }

        if ($this->comparable($heading[1]) !== $this->comparable($title)) {
            return $markdown;
        }

        return (string) preg_replace('/\A(?:[ \t]*\R)+/', '', substr($markdown, strlen($heading[0])));
    }

    private function comparable(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($text)));
    }

    /**
     * Only pictures with an https address, and only the keys the contract names.
     *
     * These URLs end up in `src`, `og:image` and the feed; a `javascript:` or
     * relative one has no business in any of them, and one bad picture is not
     * a reason to refuse the article it illustrates.
     *
     * @return list<array<string, mixed>>
     */
    private function images(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        $kept = [];

        foreach ($images as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : null;

            if (! is_array($image) || ! is_string($url) || ! $this->isWebUrl($url)) {
                continue;
            }

            $kept[] = [
                'role' => is_string($image['role'] ?? null) ? $image['role'] : null,
                'url' => $url,
                'alt' => is_string($image['alt'] ?? null) ? $image['alt'] : null,
                'anchor' => is_string($image['anchor'] ?? null) ? $image['anchor'] : null,
                'width' => is_int($image['width'] ?? null) ? $image['width'] : null,
                'height' => is_int($image['height'] ?? null) ? $image['height'] : null,
            ];
        }

        return $kept;
    }

    /*
     * https only: the page's Content-Security-Policy loads no image over
     * plain http, so one would be a broken picture and a console error.
     */
    private function isWebUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== '';
    }

    private function date(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            // In the application's zone: the column keeps no offset, so a
            // date sent as +01:00 would otherwise be stored an hour off.
            return Carbon::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
