<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\SearchIntent;
use App\Enums\SocialBand;
use App\Models\Concerns\BelongsToProject;
use App\Research\KeywordIdea;
use App\Support\Content\InvalidStateTransition;
use App\Support\Seasonality\SeasonalCurve;
use Carbon\CarbonInterface;
use Database\Factories\ContentItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A content unit (§2) — deliberately not called an article.
 *
 * One row is one locale of one thing. The unit as a whole is the set of rows
 * sharing a `locale_group_id`; its social posts are rows pointing at it through
 * `parent_id`. A LinkedIn post about the Portuguese version of a guide is a
 * row, the guide in English is a row, and they are the same unit.
 *
 * State changes only ever go through {@see transitionTo()} and the named
 * methods over it. Assigning `$item->state` directly is possible in PHP and is
 * a bug: the point of the machine is that "published" is reachable from exactly
 * one place.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $content_plan_id
 * @property string|null $content_idea_id
 * @property string|null $brand_brief_id
 * @property string|null $parent_id
 * @property string|null $signal_id
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
 * @property Carbon|null $slot_at
 * @property bool $needs_original_data
 * @property list<string> $planned_derivatives
 * @property list<string> $outline
 * @property string|null $body_markdown
 * @property string|null $body_html
 * @property string|null $summary
 * @property array<string, mixed> $json_ld
 * @property array<string, mixed> $faq_json_ld
 * @property list<string> $quotable_blocks
 * @property array<string, bool> $entity_coverage
 * @property array{entity: string, signals: int, interactions: int, captured_on: string}|null $coverage_gap
 * @property array<string, mixed> $factcheck
 * @property array<string, mixed> $author
 * @property list<string> $image_anchors
 * @property array<string, bool> $citations
 * @property Carbon|null $citations_checked_at
 * @property Carbon|null $refresh_due_at
 * @property string|null $refresh_reason
 * @property list<array<string, string>> $internal_links
 * @property list<array{url: string, title: string}>|null $offered_sources
 * @property string|null $channel_type
 * @property array<string, mixed>|null $channel_payload
 * @property SocialBand|null $social_band
 * @property Carbon|null $expires_at
 * @property array<string, mixed> $review
 * @property Carbon|null $reviewed_at
 * @property string|null $public_url
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
        'content_idea_id',
        'brand_brief_id',
        'parent_id',
        'signal_id',
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
        'slot_at',
        'needs_original_data',
        'planned_derivatives',
        'outline',
        'body_markdown',
        'body_html',
        'summary',
        'json_ld',
        'faq_json_ld',
        'quotable_blocks',
        'entity_coverage',
        'coverage_gap',
        'factcheck',
        'author',
        'image_anchors',
        'public_url',
        'review',
        'reviewed_at',
        'internal_links',
        'offered_sources',
        'channel_type',
        'channel_payload',
        'social_band',
        'expires_at',
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
        'planned_derivatives' => '[]',
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

    // -------------------------------------------------------------- social

    /**
     * True when this row is a post rather than a page (§3).
     *
     * Asked of the type, never of `parent_id`. §3 makes the parent optional for
     * a social unit — a native post answering a question nobody wrote an
     * article about has no parent and is still a post — so the two questions
     * came apart the moment the type existed. {@see isDerivative()} is the
     * other one, and it now means only what it says.
     */
    public function isSocial(): bool
    {
        return $this->type === ContentItemType::SocialPost;
    }

    /**
     * Past the window it was written for (§5).
     *
     * The TTL rides on the unit and not only on its signal because a reactive
     * draft that misses its window is killed rather than published late, and
     * the thing being killed is the draft. A null `expires_at` never expires:
     * only the reactive band sets one.
     */
    public function hasExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    // ------------------------------------------------------------ the tree

    /** True when this row hangs off another unit rather than standing alone. */
    public function isDerivative(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The social posts derived from this unit (§2, репурпоз-дерево).
     *
     * @return HasMany<self, $this>
     */
    public function derivatives(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
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
     * When in the year this subject peaks, and what to do about it (§5).
     *
     * The curve arrives from whichever keyword vendor is bound and is stored on
     * the unit by research; this is the only way anything should read it, so
     * that "which month is the peak" has one definition shared with
     * {@see KeywordIdea::seasonality()}.
     */
    public function seasonality(): SeasonalCurve
    {
        return SeasonalCurve::fromArray($this->monthly_volumes);
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
     * The channel-independent thought this draft expresses.
     *
     * @return BelongsTo<ContentIdea, $this>
     */
    public function contentIdea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class);
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
     * Why this unit exists (§3).
     *
     * The half of the feedback loop that learns by source rather than by
     * format: a month of these makes "does the news contour pay for itself" a
     * group by instead of an argument.
     *
     * @return BelongsTo<Signal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
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
     * Load the whole unit — every locale and every derivative — in a fixed
     * number of queries.
     *
     * Exists as a scope rather than as advice in a comment because the shape
     * this guards against is the default one: iterating units and touching
     * `->derivatives` inside the loop is both the obvious way to write the
     * dashboard and a query per row.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithTree(Builder $query): Builder
    {
        return $query->with(['localeVariants', 'derivatives']);
    }

    /**
     * Articles — the pages this project writes, and nothing else.
     *
     * The definition changed with §3 of the social spec and the name did not,
     * so read this before restoring the old one. "Root" used to be a fact about
     * the tree: `parent_id is null`, meaning "not a derivative", which at the
     * time was the same sentence as "an article" because the only parentless
     * rows were articles and the only social rows hung off one.
     *
     * §3 makes the parent optional for a social unit — a native post answering
     * a question nobody wrote an article about has no parent — so "parentless"
     * and "article" came apart, and every caller of this scope meant the second
     * one. Left as it was, a 300-character post would be served to a static
     * site as an article, auto-approved by the project's `autopublish` flag
     * with no reference to {@see SocialBand::canEverAutopublish()}, handed to
     * the article generation pipeline, counted against the calendar, and — the
     * worst of them — treated by planning as a subject already covered, which
     * inverts §1.3: the reverse flow matters more than the forward one, and a
     * post asking a question is a reason to write the article, not a reason
     * not to.
     *
     * So this now guarantees two things, not one: no derivative, and no social
     * post of any kind. A caller that genuinely wants every unit whatever its
     * type must say so by not calling this. {@see scopeSocial()} is the other
     * half.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query
            ->whereNull('parent_id')
            ->where('type', '<>', ContentItemType::SocialPost->value);
    }

    /**
     * Posts — the other half of {@see scopeRoots()}.
     *
     * Asked of the type and not of the tree, for the same reason
     * {@see isSocial()} is: both a native post (no parent, §3) and one cut out
     * of an article (a parent, §5's Derivative band) are posts, and the
     * governor budgets them by band rather than by where they came from. The
     * two scopes partition the table, which is what makes "is this an article
     * or a post" answerable in SQL rather than per row in PHP.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSocial(Builder $query): Builder
    {
        return $query->where('type', ContentItemType::SocialPost->value);
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
            // The instant, next to the article calendar's date. A social slot
            // stands inside a 60–90 minute duty window (§4.3), which a date
            // cannot express; the two are written together and the date is
            // derived from the instant, so they cannot disagree.
            'slot_at' => 'datetime',
            'needs_original_data' => 'boolean',
            'planned_derivatives' => 'array',
            'outline' => 'array',
            'json_ld' => 'array',
            'faq_json_ld' => 'array',
            'quotable_blocks' => 'array',
            'entity_coverage' => 'array',
            'coverage_gap' => 'array',
            'factcheck' => 'array',
            'author' => 'array',
            'image_anchors' => 'array',
            'internal_links' => 'array',
            'offered_sources' => 'array',
            'monthly_volumes' => 'array',
            'channel_payload' => 'array',
            'social_band' => SocialBand::class,
            'expires_at' => 'datetime',
            'citations' => 'array',
            'citations_checked_at' => 'datetime',
            'refresh_due_at' => 'datetime',
            'review' => 'array',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
