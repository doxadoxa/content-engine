<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One attempt to hand a unit to a channel (§2).
 *
 * Phase 2 creates the table; phase 6 writes to it, once the webhook contract
 * exists (see the open question in the phase README).
 *
 * @property string $id
 * @property string $project_id
 * @property string $channel_id
 * @property string|null $content_item_id
 * @property string $delivery_id
 * @property string|null $dispatch_key
 * @property DeliveryStatus $status
 * @property int|null $response_code
 * @property int|null $latency_ms
 * @property int $attempts
 * @property int $deferrals
 * @property array<string, mixed> $payload_snapshot
 * @property string|null $error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $next_attempt_at
 */
class WebhookDelivery extends Model
{
    use BelongsToProject;

    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'channel_id',
        'content_item_id',
        'delivery_id',
        'dispatch_key',
        'status',
        'response_code',
        'latency_ms',
        'attempts',
        'deferrals',
        'payload_snapshot',
        'error',
        'delivered_at',
        'next_attempt_at',
    ];

    protected $attributes = [
        'payload_snapshot' => '{}',
    ];

    public static function booted(): void
    {
        // The receiver's idempotency key. Generated on the way in so a row
        // cannot exist without one — a delivery with no key is a delivery the
        // receiver cannot dedupe, which is the whole guarantee of phase 6.
        static::creating(function (self $delivery): void {
            // getAttribute() rather than the property, for the reason given on
            // the same hook in ContentItem.
            if ($delivery->getAttribute('delivery_id') === null) {
                $delivery->delivery_id = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<ContentItem, $this>
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'payload_snapshot' => 'array',
            'response_code' => 'integer',
            'latency_ms' => 'integer',
            'attempts' => 'integer',
            'deferrals' => 'integer',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }
}
