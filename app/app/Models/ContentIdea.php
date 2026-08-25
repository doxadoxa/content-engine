<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\ContentFormat;
use App\Enums\PostKind;
use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One channel-independent thought in an assistant-proposed month.
 *
 * @property string $id
 * @property string $project_id
 * @property string $content_plan_id
 * @property int $proposal_version
 * @property string $idea_key
 * @property string $title
 * @property string $pillar
 * @property PostKind $kind
 * @property ContentFormat|null $content_format
 * @property string $thesis
 * @property list<string> $evidence
 * @property string $goal
 * @property string $audience
 * @property string|null $angle
 * @property string|null $shot
 * @property list<string> $channels
 * @property Carbon $scheduled_for
 */
class ContentIdea extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = [
        'content_plan_id',
        'proposal_version',
        'idea_key',
        'title',
        'pillar',
        'kind',
        'content_format',
        'thesis',
        'evidence',
        'goal',
        'audience',
        'angle',
        'shot',
        'channels',
        'scheduled_for',
    ];

    /** @return BelongsTo<ContentPlan, $this> */
    public function contentPlan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class);
    }

    /** @return HasMany<ContentItem, $this> */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    /**
     * The production shape is part of the idea, not a UI guess. Drafting and
     * Studio both read this contract so the preview cannot promise a carousel
     * while the generator silently asks for an image post.
     *
     * @return array<string, array{format: string, visual: string}>
     */
    public function plannedProduction(): array
    {
        $production = [];

        foreach ($this->channels as $channel) {
            $type = ChannelType::tryFrom($channel);
            // What this channel can actually make of the chosen format. A
            // carousel asked for on Threads becomes a single image rather than
            // being refused — the format is an intent about the idea, and an
            // idea goes to more than one channel.
            $made = $type === null ? ContentFormat::Text : $this->format()->on($type);

            $production[$channel] = match ($channel) {
                'threads' => ['format' => 'post', 'visual' => $made->visual()],
                'x' => ['format' => 'post_or_thread', 'visual' => $made->visual()],
                'instagram' => $made === ContentFormat::Carousel
                    ? ['format' => 'carousel', 'visual' => 'slides']
                    : ['format' => 'image_post', 'visual' => $made->visual()],
                default => ['format' => 'post', 'visual' => 'none'],
            };
        }

        return $production;
    }

    /**
     * Carousel or one picture, decided by what the post is.
     *
     * This used to read `$this->scheduled_for->day % 2 === 0`: a carousel on
     * even days. Nothing about the idea entered into it, so a refinement that
     * moved a post from the 12th to the 13th silently turned a carousel into a
     * single image, and a step-by-step guide landing on an odd date shipped as
     * one picture with the steps buried in the caption. {@see PostKind} answers
     * the question the date was standing in for.
     */
    public function instagramFormat(): string
    {
        return $this->format()->on(ChannelType::Instagram) === ContentFormat::Carousel
            ? 'carousel'
            : 'image';
    }

    /**
     * What this idea is made as: what somebody chose, or what the kind implies.
     *
     * The fallback is the rule that used to be the only rule — a how-to is a
     * carousel and everything else is a single image — so an idea nobody has
     * opened behaves exactly as it did before the column existed.
     */
    public function format(): ContentFormat
    {
        return $this->content_format ?? ContentFormat::impliedBy($this->kind);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'proposal_version' => 'integer',
            'kind' => PostKind::class,
            'content_format' => ContentFormat::class,
            'evidence' => 'array',
            'channels' => 'array',
            'scheduled_for' => 'date',
        ];
    }
}
