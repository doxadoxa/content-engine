<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetRole;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\SearchIntent;
use App\Media\HeroImage;
use App\Models\Concerns\BelongsToProject;
use App\Support\Content\InvalidStateTransition;
use Database\Factories\ContentItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A content unit (§2) — deliberately not called an article.
 *
 * One row is one locale of one thing. The unit as a whole is the set of rows
 * sharing a `locale_group_id`. The guide in Portuguese is a row, the guide in
 * English is a row, and they are the same unit.
 *
 * State changes only ever go through {@see transitionTo()} and the named
 * methods over it. Assigning `$item->state` directly is possible in PHP and is
 * a bug: the point of the machine is that "published" is reachable from exactly
 * one place.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $content_plan_id
 * @property string|null $brand_brief_id
 * @property string $locale_group_id
 * @property string $locale
 * @property ContentItemState $state
 * @property ContentItemType $type
 * @property string $slug
 * @property string $title
 * @property string|null $target_query
 * @property list<string> $entities
 * @property int|null $topic_difficulty
 * @property int|null $topic_volume
 * @property array<string, int>|null $monthly_volumes
 * @property int|null $serp_target_words
 * @property SearchIntent|null $intent
 * @property string|null $cluster
 * @property Carbon|null $scheduled_for
 * @property bool $needs_original_data
 * @property list<string> $outline
 * @property string|null $body_markdown
 * @property string|null $body_html
 * @property string|null $summary
 * @property array<string, mixed> $json_ld
 * @property array<string, mixed> $faq_json_ld
 * @property list<string> $quotable_blocks
 * @property array<string, bool> $entity_coverage
 * @property array<string, mixed> $factcheck
 * @property array<string, mixed> $author
 * @property list<string> $image_anchors
 * @property array<string, bool> $citations
 * @property Carbon|null $citations_checked_at
 * @property Carbon|null $refresh_due_at
 * @property string|null $refresh_reason
 * @property list<array<string, string>> $internal_links
 * @property list<array{url: string, title: string}>|null $offered_sources
 * @property array<string, mixed> $review
 * @property Carbon|null $reviewed_at
 * @property string|null $public_url
 * @property string|null $article_planning_period_id
 * @property Carbon|null $planned_publication_at
 * @property Carbon|null $published_at
 */
class ContentItem extends Model
{
    use BelongsToProject;

    /** @use HasFactory<ContentItemFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'content_plan_id',
        'article_planning_period_id',
        'planned_publication_at',
        'brand_brief_id',
        'locale_group_id',
        'locale',
        'type',
        'slug',
        'title',
        'target_query',
        'entities',
        'topic_difficulty',
        'topic_volume',
        'monthly_volumes',
        'serp_target_words',
        'intent',
        'cluster',
        'scheduled_for',
        'needs_original_data',
        'outline',
        'body_markdown',
        'body_html',
        'summary',
        'json_ld',
        'faq_json_ld',
        'quotable_blocks',
        'entity_coverage',
        'factcheck',
        'author',
        'image_anchors',
        'public_url',
        'review',
        'reviewed_at',
        'internal_links',
        'offered_sources',
        'citations',
        'citations_checked_at',
        'refresh_due_at',
        'refresh_reason',
    ];

    /**
     * The json columns default in the database, but a model that has just been
     * created has never read them back — so `$item->outline` is null on the
     * instance that made the row and `[]` on every instance after. Anything
     * that counts or iterates one fatals exactly once, on the request that
     * created it, which is the hardest possible time to notice.
     *
     * `offered_sources` is deliberately absent: null there means "we never
     * looked", which the score reads differently from "we looked and found
     * none".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => ContentItemState::Idea->value,
        'entities' => '[]',
        'outline' => '[]',
        'json_ld' => '{}',
        'faq_json_ld' => '{}',
        'quotable_blocks' => '[]',
        'entity_coverage' => '{}',
        'factcheck' => '{}',
        'author' => '{}',
        'image_anchors' => '[]',
        'internal_links' => '[]',
        'citations' => '{}',
        'review' => '{}',
    ];

    /** @return BelongsTo<ArticlePlanningPeriod, $this> */
    public function articlePlanningPeriod(): BelongsTo
    {
        return $this->belongsTo(ArticlePlanningPeriod::class);
    }

    /** @return HasOne<ArticleSchedule, $this> */
    public function articleSchedule(): HasOne
    {
        return $this->hasOne(ArticleSchedule::class);
    }

    public static function booted(): void
    {
        // A unit that is not told which group it belongs to starts its own.
        // Doing this here rather than in the factory means a row created by a
        // pipeline, a seeder or an operator all get one, and the NOT NULL
        // column never has to be remembered.
        static::creating(function (self $item): void {
            // getAttribute(), not the property: the property is typed
            // non-nullable because it is non-nullable *after* this hook, and
            // reading it directly is how that promise gets checked before it
            // has been kept.
            if ($item->getAttribute('locale_group_id') === null) {
                $item->locale_group_id = (string) Str::ulid();
            }
        });
    }

    // ---------------------------------------------------------------- state

    /**
     * The one door into the state machine.
     *
     * @throws InvalidStateTransition
     */
    public function transitionTo(ContentItemState $next): self
    {
        if (! $this->state->canTransitionTo($next)) {
            throw InvalidStateTransition::between($this->state, $next);
        }

        $this->state = $next;

        // Stamped here rather than by the caller so the timestamp cannot
        // disagree with the state. Re-stamped on a re-publish after a refresh,
        // which is correct: it is when readers saw the current text.
        if ($next === ContentItemState::Published) {
            $this->published_at = now();
        }

        $this->save();

        return $this;
    }

    /** Accepted into a plan and waiting for a worker. */
    public function markQueued(): self
    {
        return $this->transitionTo(ContentItemState::Queued);
    }

    /** A pipeline has picked it up. */
    public function markGenerating(): self
    {
        return $this->transitionTo(ContentItemState::Generating);
    }

    /** Generation finished and there is a body to read. */
    public function markDrafted(): self
    {
        return $this->transitionTo(ContentItemState::Draft);
    }

    /** A human signed off on the draft. */
    public function approve(): self
    {
        return $this->transitionTo(ContentItemState::Approved);
    }

    /**
     * A human takes their approval back (§7).
     *
     * Deliberately not an edge on {@see ContentItemState::allowedNext()}, and
     * the distinction is the point: that map is what the *engine* may do to a
     * unit on its own, and its strictness here is load-bearing. `finalise_draft`
     * ends by moving a unit to `draft`, so an approved unit regenerated by
     * accident would silently throw away the approval — on 2026-08-09 it tried
     * exactly that and the map is what stopped it. Widening the map to allow
     * this would remove the guard along with the inconvenience.
     *
     * So sending back is a method with a name and a caller who is a person. It
     * is the only way out of `approved` other than publishing, and it exists
     * because there was none: an article approved with a fault in it could be
     * published or ignored, and nothing else.
     *
     * Published is not reachable from here on purpose. Text somebody may have
     * linked to is not withdrawn by an approvals screen; that is what
     * `refreshing` is for.
     *
     * @throws InvalidStateTransition
     */
    public function returnForRework(): self
    {
        if ($this->state !== ContentItemState::Approved) {
            throw InvalidStateTransition::between($this->state, ContentItemState::Draft);
        }

        $this->state = ContentItemState::Draft;
        $this->save();

        return $this;
    }

    /** Delivered to at least one channel. */
    public function markPublished(): self
    {
        return $this->transitionTo(ContentItemState::Published);
    }

    /** The feedback loop asked for a rewrite of live text. */
    public function startRefresh(): self
    {
        return $this->transitionTo(ContentItemState::Refreshing);
    }

    public function canTransitionTo(ContentItemState $next): bool
    {
        return $this->state->canTransitionTo($next);
    }

    /**
     * Every locale of this unit, including this row.
     *
     * Keyed on `locale_group_id` on both sides, which is what makes it one
     * `where in` for any number of units rather than a query per row.
     *
     * @return HasMany<self, $this>
     */
    public function localeVariants(): HasMany
    {
        return $this->hasMany(self::class, 'locale_group_id', 'locale_group_id');
    }

    /**
     * Create the same unit in another locale, joined to this one's group.
     *
     * `topic_difficulty` and `topic_volume` are deliberately not carried over.
     * They are per-keyword *and* per-market: "limpeza de janelas" in Portugal
     * and "window cleaning" in the UK are the same unit and nowhere near the
     * same search volume, so copying the parent's numbers would invent data the
     * planner of phase 4 then makes decisions on.
     */
    public function addLocale(string $locale, string $slug, string $title): self
    {
        return static::query()->create([
            'content_plan_id' => $this->content_plan_id,
            'brand_brief_id' => $this->brand_brief_id,
            'locale_group_id' => $this->locale_group_id,
            'locale' => $locale,
            'type' => $this->type,
            'slug' => $slug,
            'title' => $title,
            'target_query' => $this->target_query,
            'entities' => $this->entities,
        ]);
    }

    // ----------------------------------------------------------- relations

    /**
     * @return BelongsTo<ContentPlan, $this>
     */
    public function contentPlan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class);
    }

    /**
     * The brief version this was written from.
     *
     * @return BelongsTo<BrandBrief, $this>
     */
    public function brandBrief(): BelongsTo
    {
        return $this->belongsTo(BrandBrief::class);
    }

    /**
     * The pictures this unit currently has.
     *
     * Filtered on the relation rather than at each call site, because "the
     * pictures of this article" is what every reader means and the one that
     * forgot to say so is the bug this exists to prevent: `ArticleScore` counted
     * rows, a rewritten article had twelve inline rows for three pictures, and
     * the data panel told the operator it had thirteen images.
     *
     * A rewrite replaces the body and the new body names none of the old files,
     * so they are marked superseded rather than deleted — the file is still
     * good, the row still says what it cost, and a rewrite that came out worse
     * than what it replaced can still be looked at. {@see everyAsset()} is that
     * history; this is the article.
     *
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class)->whereNull('superseded_at');
    }

    /**
     * Every picture ever made for this unit, superseded ones included.
     *
     * Named so that a reader who wants history has to ask for it, and one who
     * writes `assets` gets the article.
     *
     * @return HasMany<Asset, $this>
     */
    public function everyAsset(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * Retire the pictures inside the body, because the body is about to change.
     *
     * Called when a rewrite saves a new draft. Only the inline ones: a hero is
     * the article's picture rather than a picture in it, it is not named in the
     * body, and it stays valid for a piece about the same subject —
     * {@see HeroImage::for()} reuses it deliberately.
     */
    public function supersedeInlineAssets(): void
    {
        $this->everyAsset()
            ->where('role', AssetRole::Inline)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);
    }

    /**
     * Search performance, newest first (§9.1).
     *
     * @return HasMany<ContentMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(ContentMetric::class)->orderByDesc('measured_on');
    }

    // -------------------------------------------------------------- scopes

    /**
     * Load the whole unit — every locale — in a fixed number of queries.
     *
     * Exists as a scope rather than as advice in a comment because the shape
     * this guards against is the default one: iterating units and touching
     * `->localeVariants` inside the loop is both the obvious way to write the
     * dashboard and a query per row.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithLocaleVariants(Builder $query): Builder
    {
        return $query->with(['localeVariants']);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInState(Builder $query, ContentItemState $state): Builder
    {
        return $query->where('state', $state);
    }

    /**
     * Units whose last assistant check found at least one citation.
     *
     * The assistant names are dynamic JSON object keys, so a fixed
     * `whereJsonContains` path cannot express this. PostgreSQL's set-returning
     * JSON function keeps the count in the database instead of hydrating every
     * historical article just to inspect its booleans in PHP.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithCitation(Builder $query): Builder
    {
        return $query->whereRaw(<<<'SQL'
            exists (
                select 1
                from json_each_text(citations) as citation
                where citation.value = 'true'
            )
            SQL);
    }

    protected function casts(): array
    {
        return [
            'state' => ContentItemState::class,
            'type' => ContentItemType::class,
            'entities' => 'array',
            'intent' => SearchIntent::class,
            'scheduled_for' => 'date',
            'planned_publication_at' => 'datetime',
            'needs_original_data' => 'boolean',
            'outline' => 'array',
            'json_ld' => 'array',
            'faq_json_ld' => 'array',
            'quotable_blocks' => 'array',
            'entity_coverage' => 'array',
            'factcheck' => 'array',
            'author' => 'array',
            'image_anchors' => 'array',
            'internal_links' => 'array',
            'offered_sources' => 'array',
            'monthly_volumes' => 'array',
            'citations' => 'array',
            'citations_checked_at' => 'datetime',
            'refresh_due_at' => 'datetime',
            'review' => 'array',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
