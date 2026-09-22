<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property string $purchase_source_id
 * @property string|null $purchase_record_id
 * @property string $event_id
 * @property string $payload_hash
 * @property array<string, mixed> $payload
 * @property string $disposition
 */
class PurchaseEvent extends Model
{
    use BelongsToProject;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['purchase_source_id', 'purchase_record_id', 'event_id', 'payload_hash', 'payload', 'disposition', 'recorded_by', 'received_at'];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Purchase events are immutable. Record a new revision to correct a sale.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'immutable_datetime'];
    }
}
