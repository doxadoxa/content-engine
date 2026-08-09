<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignalKind;
use App\Enums\SignalSource;
use App\Models\Concerns\BelongsToProject;
use App\Pipelines\Steps\SocialListen\Deduplicate;
use App\Pipelines\Steps\SocialListen\FeedPlanner;
use App\Pipelines\Steps\SocialListen\Normalise;
use Carbon\CarbonInterface;
use Database\Factories\SignalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Normalizer;

/**
 * A reason to say something (§3).
 *
 * The engine already had ideas, plans and units; what it had nowhere to put was
 * the cause. A signal is scored, ages out, is deduplicated against a window of
 * history, and is pointed at afterwards by whatever it produced — four things a
 * column on `content_items` cannot do.
 *
 * ⚠️ **`consumed_at` has no writer yet, and nothing below pretends otherwise.**
 * {@see markConsumed()} exists and nothing in the engine calls it, so every row
 * in the table has a null `consumed_at` and the `whereNull('consumed_at')`
 * clauses in {@see scopeLive()} and {@see scopeReapable()} are both, today,
 * dead. They are kept rather than deleted because the column is the right idea
 * in the right place — but consumption belongs at **publication** of whatever
 * the signal produced, not at planning: a question is a reason to write an
 * article *and* a reason to post (§1.3), and marking it used the moment a
 * planner looked at it would let the article silently cancel the post.
 * {@see FeedPlanner} reaches the same conclusion from the other side and leans
 * on `content_items.signal_id` instead. The writer is owed by the
 * publishing contour of §4.3 — `social_plan` / `social_draft`, phase 12.4 —
 * which is the first thing in the engine that knows a signal produced something
 * that actually went out.
 *
 * ⚠️ **Bind instants here in UTC.** `occurred_at`, `expires_at` and
 * `consumed_at` are `timestamptz`, which does not save a query that binds a
 * local time: Laravel formats a bound `DateTimeInterface` with
 * `format('Y-m-d H:i:s')` and drops the offset, so Postgres reads the digits in
 * the session timezone. `where('occurred_at', '>', CarbonImmutable::now($project->timezone)->subDay())`
 * is therefore wrong by that project's offset — a window an hour or two long in
 * the wrong direction, which is exactly enough for the 30-day dedup of §4.1 to
 * miss a subject or the reaper to take a signal early. Call `->utc()` on the
 * bound value. The session timezone itself is pinned in `config/database.php`
 * so the mistake is at least reproducible.
 *
 * @property string $id
 * @property string $project_id
 * @property SignalKind $kind
 * @property SignalSource $source
 * @property string|null $external_id
 * @property string $fingerprint
 * @property string $title
 * @property string|null $url
 * @property list<string> $entities
 * @property Carbon $occurred_at
 * @property Carbon|null $expires_at
 * @property int $weight
 * @property array<string, mixed> $raw
 * @property Carbon|null $consumed_at ⚠️ written by nothing yet — see above
 */
class Signal extends Model
{
    use BelongsToProject;

    /** @use HasFactory<SignalFactory> */
    use HasFactory;

    use HasUlids;

    /** How much of a sha256 the fingerprint keeps. 128 bits, hex. */
    private const int FINGERPRINT_LENGTH = 32;

    protected $fillable = [
        'kind',
        'source',
        'external_id',
        'fingerprint',
        'title',
        'url',
        'entities',
        'occurred_at',
        'expires_at',
        'weight',
        'raw',
        'consumed_at',
    ];

    /**
     * The json columns default in the database, but a model that has just been
     * created has never read them back — so `$signal->entities` is null on the
     * instance that made the row and `[]` on every instance after.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'entities' => '[]',
        'raw' => '{}',
    ];

    /**
     * The subject of a signal, as a short stable hash.
     *
     * This is what makes the dedup of §4.1 work. One hot subject arrives five
     * times — from `keyword_search`, from a webhook, from two RSS feeds and
     * from the keyword pool — under five different external ids, and keying the
     * window on the platform's id would let all five through and produce five
     * nearly identical drafts. Keying it on the subject collapses them to one.
     *
     * Stable across two things that vary for no meaningful reason. Case,
     * punctuation and stray whitespace, because "Lisbon water bill — up 12%"
     * and "lisbon water bill up 12" are the same news item wearing two house
     * styles. And the order of the entity list, because whichever intake
     * happened to resolve entities first should not decide whether the second
     * one counts as new.
     *
     * @param  list<string>  $entities
     *
     * @throws InvalidArgumentException when there is no subject to hash at all
     */
    public static function fingerprintFor(string $title, array $entities): string
    {
        $normalised = array_values(array_filter(
            array_map(self::normalise(...), $entities),
            // `!== ''` rather than truthiness. `array_filter()` with no
            // callback drops "0" along with "", and an entity vocabulary
            // holding a plan tier, a model number or a rating has one: two
            // different subjects would then share a fingerprint and the second
            // would be deduped away and never drafted.
            static fn (string $entity): bool => $entity !== '',
        ));

        // Sorted rather than kept in arrival order: the set is the subject, the
        // sequence is an accident of whichever resolver ran.
        //
        // SORT_STRING, and this is not a stylistic preference. The default
        // SORT_REGULAR compares numeric-looking strings numerically and the
        // rest lexically, which is not a transitive comparator: for
        // ["115", "24f", "6"] it holds that "6" < "115" numerically, "115" <
        // "24f" lexically and "24f" < "6" lexically, so the result depends on
        // the order the array arrived in. Entity vocabularies carrying model
        // numbers, years, postcodes or plan tiers hit exactly that, and an
        // order-dependent fingerprint lets the same subject escape the 30-day
        // window of §4.1 — the one thing the fingerprint exists to close.
        sort($normalised, SORT_STRING);

        $subject = self::normalise($title);

        // A signal with neither a title nor an entity has no subject, and the
        // honest fingerprint of nothing is not a hash — it is a refusal.
        // Hashing the empty string would give every untitled signal in a
        // project the same fingerprint, so the dedup of §4.1 would suppress
        // all of them but the first and the intake would look like it was
        // working. Guarded here rather than documented at the call site
        // because the call site is a gateway parsing somebody else's JSON,
        // which is precisely where an empty title arrives.
        if ($subject === '' && $normalised === []) {
            throw new InvalidArgumentException(
                'A signal needs a title or at least one entity to be fingerprinted: '
                .'hashing an empty subject would collide every untitled signal in the project into one.'
            );
        }

        return substr(
            hash('sha256', implode('|', [$subject, ...$normalised])),
            0,
            self::FINGERPRINT_LENGTH,
        );
    }

    /**
     * Units written because of this signal (§3 — the loop learns by source).
     *
     * @return HasMany<ContentItem, $this>
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    /**
     * @return HasMany<Interaction, $this>
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }

    /**
     * Past its TTL (§5).
     *
     * A null `expires_at` never expires — it means "this signal has no window",
     * which is the normal case for a corpus gap or a seasonal curve.
     *
     * Two kinds of row do carry one, and they mean different things. For the
     * reactive band the window is a kill: §5 destroys a draft that misses it
     * rather than publishing it late. For a question it is a shelf life — see
     * {@see Normalise} — because a live
     * signal is also a block, and a question nobody ever planned would
     * otherwise stop its own subject being raised again for the life of the
     * project. Nothing is killed when a question expires; the article it
     * already produced, if it produced one, is not touched.
     */
    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    /**
     * Record that a plan or a draft used it.
     *
     * ⚠️ Nothing calls this. See the class docblock: the caller it is waiting
     * for is the publishing contour of §4.3, and until that exists every reader
     * of `consumed_at` is reading a column that is null on every row.
     */
    public function markConsumed(): void
    {
        $this->consumed_at = now();

        $this->save();
    }

    /**
     * Still worth acting on: inside its window and not already used.
     *
     * The planner's only entry point into this table, which is why the two
     * conditions live together rather than being remembered at each call site —
     * a query that filters on one and forgets the other either re-drafts
     * something already published or comments on the day before yesterday.
     *
     * ⚠️ Only one of the two conditions is doing anything today. Nothing writes
     * `consumed_at` (see the class docblock), so "not already used" is a
     * promise this scope does not currently keep, and every caller that relies
     * on it — {@see Deduplicate}, which reads this as "the set a new arrival
     * would duplicate", and {@see FeedPlanner}, which reads it as the pool — is
     * in fact protected by something else: the former by the subject
     * fingerprint and the platform id, the latter by
     * `whereDoesntHave('contentItems')`. Both say so where they read it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query
            ->whereNull('consumed_at')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Safe for a reaper to delete: dead, unused, and pointed at by nothing.
     *
     * Three conditions, and each one is a row a naive reaper would destroy.
     *
     * **Only what actually expired.** `expires_at` is set by the reactive band
     * and by nothing else (§5), so a reaper keyed on age instead — "signals
     * older than 30 days" — would erase every corpus gap and seasonal curve in
     * the table, none of which has a window at all. A null `expires_at` means
     * "no window", never "expired long ago".
     *
     * **Nothing consumed.** A consumed signal is the answer to "why did we
     * publish this", and §3 makes that answer the point of the table. ⚠️ This
     * clause protects nothing yet — no row has a `consumed_at` — and what is
     * actually keeping the reaper off a signal that produced something is the
     * third condition below. When §4.3's publishing contour starts writing the
     * column, this becomes the clause that spares a signal whose unit was
     * published and later deleted.
     *
     * **Nothing a unit still points at.** `content_items.signal_id` is
     * `nullOnDelete`, so deleting the signal does not fail — it quietly blanks
     * the column, and the per-source attribution of §3 loses the news contour
     * first, which is the exact question the spec says this table exists to
     * answer with a number rather than an opinion.
     *
     * Conversations are deliberately not in the exclusion. `interactions
     * .signal_id` is also `nullOnDelete`, but it records that an incoming reply
     * was *also* worth writing about — a back-reference to the conversation
     * itself, which keeps its own text and its own state. Nothing the loop
     * learns from is lost when it goes null. If 12.3 ever attributes a reply's
     * outcome to the signal that prompted it, this is the line to revisit.
     *
     * @param  Builder<self>  $query
     */
    public function scopeReapable(Builder $query): void
    {
        $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('consumed_at')
            ->whereDoesntHave('contentItems');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SignalKind::class,
            'source' => SignalSource::class,
            'entities' => 'array',
            'occurred_at' => 'datetime',
            'expires_at' => 'datetime',
            'weight' => 'integer',
            'raw' => 'array',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Lowercased, stripped of everything that is not a letter or a digit.
     *
     * Unicode-normalised to NFC first, and that step is not cosmetic. A
     * combining acute is its own codepoint in NFD and matches `\p{M}` rather
     * than `\p{L}`, so the replace below turns the decomposed "água" into
     * "a gua" while the composed one stays "água" — the same headline from two
     * sources, two fingerprints, two nearly identical drafts, in the language
     * the project actually runs in. Apple clients and several RSS pipelines
     * emit NFD, and the Threads API returns whatever the poster typed.
     *
     * `isNormalized()` first because it is a cheap scan and the overwhelming
     * majority of input is already NFC; `normalize()` allocates. A conversion
     * that fails leaves the value alone rather than replacing it with the empty
     * string that a bare `(string)` cast on `false` would produce.
     */
    private static function normalise(string $value): string
    {
        if (! Normalizer::isNormalized($value, Normalizer::FORM_C)) {
            $composed = Normalizer::normalize($value, Normalizer::FORM_C);

            if (is_string($composed)) {
                $value = $composed;
            }
        }

        return Str::squish((string) preg_replace(
            '/[^\p{L}\p{N}]+/u',
            ' ',
            mb_strtolower($value),
        ));
    }
}
