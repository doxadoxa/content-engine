<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SitePageKind;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\SitePageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A page that was on the project's site before this engine existed.
 *
 * Not a {@see ContentItem}: nothing here was planned, written or scheduled by
 * us, and it has no state to move through. What it has is a topic somebody has
 * already covered, which is the one thing the planner has to know about it.
 *
 * @property string $id
 * @property string $project_id
 * @property string $url
 * @property string $title
 * @property string|null $description
 * @property string|null $body
 * @property Carbon|null $published_at
 * @property bool $is_article
 * @property SitePageKind|null $page_kind
 * @property Carbon|null $tracked_at
 * @property string|null $canonical_url
 * @property string|null $canonical_hash
 * @property string|null $locale
 * @property string|null $content_item_id
 * @property string|null $channel_id
 * @property string|null $cms_object_type
 * @property string|null $cms_object_id
 * @property-read PageSnapshot|null $latestSnapshot
 * @property Carbon|null $read_at
 */
class SitePage extends Model
{
    use BelongsToProject;

    /** @use HasFactory<SitePageFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'url',
        'title',
        'description',
        'body',
        'published_at',
        'is_article',
        'page_kind',
        'read_at',
        'tracked_at', 'canonical_url', 'canonical_hash', 'locale', 'content_item_id',
        'channel_id', 'cms_object_type', 'cms_object_id',
    ];

    /** @return HasMany<PageSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(PageSnapshot::class)->orderByDesc('id');
    }

    /** @return HasOne<PageSnapshot, $this> */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(PageSnapshot::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('source_kind', 'public'));
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeTracked(Builder $query): Builder
    {
        return $query->whereNotNull('tracked_at');
    }

    /**
     * Pages worth comparing a planned topic against.
     *
     * A service page or a contact form is not a topic somebody has covered, and
     * excluding an article because the site has a /pricing page would be worse
     * than not checking at all.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeArticles(Builder $query): Builder
    {
        return $query->where('is_article', true);
    }

    /**
     * The pages where the business states its own offer, with the text to
     * prove it.
     *
     * The evidence corpus, and the reason `body` exists. Ordered by URL so that
     * a plan written twice from an unchanged site is written from the same
     * pages in the same order.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCommercial(Builder $query): Builder
    {
        return $query->where('page_kind', SitePageKind::Commercial)
            ->whereNotNull('body')
            ->orderBy('url');
    }

    /**
     * What is embedded: the title and, where the page has been read, its own
     * description. Together they are what the page is about, in the words the
     * site itself used.
     */
    public function embeddableText(): string
    {
        return trim($this->title."\n".($this->description ?? ''));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'read_at' => 'datetime',
            'tracked_at' => 'datetime',
            'is_article' => 'boolean',
            'page_kind' => SitePageKind::class,
        ];
    }
}
