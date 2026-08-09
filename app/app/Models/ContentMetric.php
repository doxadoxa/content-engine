<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Database\Factories\ContentMetricFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day's search performance for one unit (§9.1).
 *
 * @property string $content_item_id
 * @property Carbon $measured_on
 * @property int $impressions
 * @property int $clicks
 * @property int|null $position_tenths
 * @property bool $indexed
 * @property int|null $sessions
 * @property int|null $engaged_sessions
 * @property int|null $engagement_seconds
 */
class ContentMetric extends Model
{
    use BelongsToProject;

    /** @use HasFactory<ContentMetricFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'content_item_id',
        'measured_on',
        'impressions',
        'clicks',
        'position_tenths',
        'indexed',
        'sessions',
        'engaged_sessions',
        'engagement_seconds',
    ];

    /**
     * @return BelongsTo<ContentItem, $this>
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** Average position, back in the units a human reads. */
    public function position(): ?float
    {
        return $this->position_tenths === null ? null : $this->position_tenths / 10;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measured_on' => 'date',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'position_tenths' => 'integer',
            'indexed' => 'boolean',
        ];
    }
}
