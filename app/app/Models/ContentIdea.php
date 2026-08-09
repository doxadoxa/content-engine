<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $thesis
 * @property list<string> $evidence
 * @property string $goal
 * @property string $audience
 * @property string|null $angle
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
        'thesis',
        'evidence',
        'goal',
        'audience',
        'angle',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'proposal_version' => 'integer',
            'evidence' => 'array',
            'channels' => 'array',
            'scheduled_for' => 'date',
        ];
    }
}
