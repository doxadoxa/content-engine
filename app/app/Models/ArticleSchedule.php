<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $project_id
 * @property string $content_item_id
 * @property string|null $channel_id
 * @property CarbonImmutable $publish_at
 * @property string $local_date
 * @property string $local_time
 * @property string $timezone
 * @property string $mode
 * @property string $status
 * @property string $origin
 * @property int|null $requested_by
 * @property int $version
 * @property string|null $blocked_reason
 * @property string|null $delivery_id
 * @property ContentItem $contentItem
 * @property WebhookDelivery|null $delivery
 */
class ArticleSchedule extends Model
{
    use BelongsToProject, HasUlids;

    protected $guarded = ['id', 'project_id'];

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<WebhookDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['publish_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
