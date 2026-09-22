<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The latest authoritative state of one sale; receipt retries never add sales.
 *
 * @property string $id
 * @property string $project_id
 * @property string $purchase_source_id
 * @property string|null $site_page_id
 * @property string $transaction_id
 * @property int $revision
 * @property string $status
 * @property int $amount_minor
 * @property int $refunded_minor
 * @property string $currency
 * @property CarbonImmutable|null $purchased_at
 * @property CarbonImmutable $occurred_at
 * @property string|null $landing_url
 * @property string $attribution_status
 * @property bool|null $is_new_customer
 * @property array<int, array<string, mixed>> $items
 * @property string|null $evidence
 * @property string $payload_hash
 */
class PurchaseRecord extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = [
        'purchase_source_id', 'site_page_id', 'transaction_id', 'revision', 'status',
        'amount_minor', 'refunded_minor', 'currency', 'purchased_at', 'occurred_at',
        'landing_url', 'attribution_status', 'is_new_customer', 'items', 'evidence', 'payload_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer', 'amount_minor' => 'integer', 'refunded_minor' => 'integer',
            'purchased_at' => 'immutable_datetime', 'occurred_at' => 'immutable_datetime',
            'is_new_customer' => 'boolean', 'items' => 'array',
        ];
    }
}
