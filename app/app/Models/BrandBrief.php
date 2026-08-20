<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use App\Support\Brand\VisualStyle;
use App\Support\Tenancy\CurrentProject;
use Database\Factories\BrandBriefFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The Brand Brief (§3.1) — the answer to "звучит не как мы".
 *
 * Versioned, and versioned by append: {@see revise()} writes a new row and
 * flips the active flag, and nothing else in the engine updates a brief in
 * place. That is what makes §2's promise keepable — "старые публикации знают,
 * на какой версии сделаны" — because a published article holds the id of the
 * row it was written from, and that row still says what it said then.
 *
 * @property string $id
 * @property string $project_id
 * @property int $version
 * @property bool $is_active
 * @property string $positioning
 * @property string $audience
 * @property string $tone
 * @property string $visual_language
 * @property string $brand_colour
 * @property string $brand_ink
 * @property string $brand_accent
 * @property list<string> $brand_palette
 * @property string $brand_typeface
 * @property string $carousel_cover
 * @property string $overlay_position
 * @property string $overlay_case
 * @property list<string> $forbidden_topics
 * @property list<string> $examples_liked
 * @property list<string> $examples_disliked
 * @property list<string> $competitors
 * @property string|null $change_note
 */
class BrandBrief extends Model
{
    use BelongsToProject;

    /** @use HasFactory<BrandBriefFactory> */
    use HasFactory;

    use HasUlids;

    /** @var list<string> */
    public const array TEXT_FIELDS = [
        'positioning',
        'audience',
        'tone',
        'visual_language',
    ];

    /**
     * The look as values a renderer draws with, beside the prose above.
     *
     * Their own group because they are neither free text nor a list: each has a
     * constrained set of legal values, and {@see VisualStyle} is what enforces
     * them at the point of use. They are still CONTENT_FIELDS, so a change to
     * the brand colour makes a new version like any other edit — which is what
     * lets a post published last month say what colour it was made in.
     *
     * @var list<string>
     */
    public const array VISUAL_FIELDS = [
        'brand_colour',
        'brand_ink',
        'brand_accent',
        // Ordered by weight on the page. Versioned with the rest, so a post
        // published last month can still say which palette drew it.
        'brand_palette',
        // A key of VisualStyle::TYPEFACES, so it names a face the renderer's
        // image actually carries rather than one Chromium would fall back from.
        'brand_typeface',
        // `photo` or `type`. A consistency decision, made once per brand.
        'carousel_cover',
        'overlay_position',
        'overlay_case',
    ];

    /** @var list<string> */
    public const array LIST_FIELDS = [
        'forbidden_topics',
        'examples_liked',
        'examples_disliked',
        'competitors',
    ];

    /**
     * The fields an operator edits. `version` and `is_active` are not here on
     * purpose: they are bookkeeping, and the only correct way to set them is
     * {@see revise()}.
     *
     * @var list<string>
     */
    public const array CONTENT_FIELDS = [
        ...self::TEXT_FIELDS,
        ...self::VISUAL_FIELDS,
        ...self::LIST_FIELDS,
    ];

    protected $fillable = [
        ...self::CONTENT_FIELDS,
        'change_note',
    ];

    protected $attributes = [
        'positioning' => '',
        'audience' => '',
        'tone' => '',
        'visual_language' => '',
        'brand_colour' => VisualStyle::DEFAULT_COLOUR,
        'brand_ink' => VisualStyle::DEFAULT_INK,
        'brand_accent' => VisualStyle::DEFAULT_ACCENT,
        'brand_palette' => '[]',
        'brand_typeface' => VisualStyle::DEFAULT_TYPEFACE,
        'carousel_cover' => VisualStyle::DEFAULT_COVER,
        'overlay_position' => VisualStyle::DEFAULT_POSITION,
        'overlay_case' => VisualStyle::DEFAULT_CASE,
        'forbidden_topics' => '[]',
        'examples_liked' => '[]',
        'examples_disliked' => '[]',
        'competitors' => '[]',
    ];

    /**
     * Save a change as a new version and make it the active one.
     *
     * `$changes` is a partial edit: anything absent is carried over from the
     * current active version, so changing the tone does not silently blank the
     * competitor list. A key that is present but null *clears* that field — see
     * {@see normalise()}. The whole thing is one transaction because the
     * partial unique index refuses two active rows: deactivating and inserting
     * have to succeed or fail together, or a failed insert leaves the project
     * with no active brief at all.
     *
     * Two operators saving at the same instant is resolved by the database, not
     * here: both read the same `max(version)` and the loser hits the unique
     * index on `(project_id, version)`. That is a failed save with nothing
     * written, which is the right outcome — the alternative to an error is one
     * of the two edits silently disappearing.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function revise(Project $project, array $changes, ?string $note = null): self
    {
        // Run *as* the project being revised. Editing project B's brief while
        // the operator is in project A is refused by BelongsToProject, and
        // rightly: without this, revise() would be callable only from inside
        // the tenant it targets, which is not true of seeders, the onboarding
        // agent of phase 9, or any maintenance command.
        return app(CurrentProject::class)->run($project, fn (): self => DB::transaction(function () use ($project, $changes, $note): self {
            $current = self::activeFor($project);

            $carried = $current === null
                ? []
                : $current->only(self::CONTENT_FIELDS);

            $next = self::acrossProjects()
                ->where('project_id', $project->getKey())
                ->max('version');

            $brief = new self([
                ...$carried,
                ...self::normalise($changes),
                'change_note' => $note,
            ]);

            $brief->project_id = $project->getKey();
            $brief->version = ((int) $next) + 1;

            // Deactivate before inserting, not after: the index is enforced per
            // statement, so the other order fails on the insert.
            if ($current !== null) {
                self::acrossProjects()
                    ->whereKey($current->getKey())
                    ->update(['is_active' => false]);
            }

            $brief->is_active = true;
            $brief->save();

            return $brief;
        }));
    }

    public static function activeFor(Project $project): ?self
    {
        return self::acrossProjects()
            ->where('project_id', $project->getKey())
            ->where('is_active', true)
            ->first();
    }

    /**
     * The units written from this version — "какие публикации на ней сделаны".
     *
     * @return HasMany<ContentItem, $this>
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    /**
     * The brief as it reaches a pipeline prompt.
     *
     * A stub with a real contract: phase 3 is the first consumer, and what it
     * needs from phase 2 is the guarantee that the same brief compiles to the
     * same bytes. Without that, a prompt cache keyed on this string misses at
     * random and two runs of one pipeline are not comparable — which is the
     * data §9 wants to pick models on.
     *
     * Deterministic here means: fixed section order, no timestamps, no ids, and
     * lists in the order the operator entered them.
     */
    public function compileToPrompt(): string
    {
        $sections = [
            'Positioning' => $this->positioning,
            'Audience' => $this->audience,
            'Tone of voice' => $this->tone,
            'Visual language' => $this->visual_language,
            'Never write about' => $this->asLines($this->forbidden_topics),
            'Good examples' => $this->asLines($this->examples_liked),
            'Bad examples' => $this->asLines($this->examples_disliked),
            'Competitors' => $this->asLines($this->competitors),
        ];

        $rendered = [];

        foreach ($sections as $heading => $body) {
            $body = trim($body);

            if ($body === '') {
                continue;
            }

            $rendered[] = "## {$heading}\n{$body}";
        }

        return implode("\n\n", $rendered);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'brand_palette' => 'array',
            'forbidden_topics' => 'array',
            'examples_liked' => 'array',
            'examples_disliked' => 'array',
            'competitors' => 'array',
        ];
    }

    /**
     * Keep only the editable fields, and turn "absent" into the column's empty
     * value rather than null.
     *
     * Every text column here is NOT NULL with a default of `''`, and every list
     * column is NOT NULL json. A null therefore does not clear a field, it
     * fails the insert — and null is exactly what arrives when an operator
     * empties a textarea, because Laravel's global ConvertEmptyStringsToNull
     * middleware rewrites `''` to null before the request is ever validated.
     *
     * Done here rather than in the form request because revise() is the only
     * writer this table has, and its other callers — seeders, the console, the
     * onboarding agent of phase 9 — never pass through a request at all.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function normalise(array $changes): array
    {
        $clean = [];

        foreach (array_intersect_key($changes, array_flip(self::CONTENT_FIELDS)) as $field => $value) {
            $clean[$field] = match (true) {
                $value !== null => $value,
                in_array($field, self::LIST_FIELDS, true) => [],
                // Clearing a colour or a corner means "back to the house
                // value", not "no value". A renderer with an empty string for
                // a fill draws nothing, and the operator who blanked the field
                // meant to undo their choice rather than to break the panel.
                in_array($field, self::VISUAL_FIELDS, true) => self::visualDefault($field),
                default => '',
            };
        }

        return $clean;
    }

    /**
     * The house value for a visual field an operator cleared.
     *
     * @return string|list<string>
     */
    private static function visualDefault(string $field): string|array
    {
        return match ($field) {
            'brand_colour' => VisualStyle::DEFAULT_COLOUR,
            'brand_ink' => VisualStyle::DEFAULT_INK,
            // Empty, which VisualStyle reads as "the ink". Clearing the accent
            // is how an operator says they have not got one, and it has to be
            // expressible — the other visual fields have no such state.
            'brand_accent' => VisualStyle::DEFAULT_ACCENT,
            // The one visual field that is a list, so its empty is a list too.
            // Nothing to reach for is a real answer here — it is the state every
            // brief written before this column had, and the fallbacks are built
            // to read it as "carry on exactly as before".
            'brand_palette' => [],
            'brand_typeface' => VisualStyle::DEFAULT_TYPEFACE,
            'carousel_cover' => VisualStyle::DEFAULT_COVER,
            'overlay_position' => VisualStyle::DEFAULT_POSITION,
            default => VisualStyle::DEFAULT_CASE,
        };
    }

    /**
     * @param  list<string>  $values
     */
    private function asLines(array $values): string
    {
        return implode("\n", array_map(
            static fn (string $value): string => '- '.trim($value),
            array_filter($values, static fn (string $value): bool => trim($value) !== ''),
        ));
    }
}
