<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $kind
 * @property string|null $secret
 * @property bool $is_enabled
 * @property bool $is_primary
 * @property CarbonImmutable|null $tracking_started_at
 * @property CarbonImmutable|null $first_received_at
 * @property CarbonImmutable|null $last_received_at
 * @property CarbonImmutable|null $verified_at
 * @property string|null $verification_note
 */
class PurchaseSource extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = ['name', 'kind', 'secret', 'is_enabled', 'is_primary', 'tracking_started_at', 'first_received_at', 'last_received_at', 'verified_at', 'verification_note'];

    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted', 'is_enabled' => 'boolean', 'is_primary' => 'boolean',
            'tracking_started_at' => 'immutable_datetime', 'last_received_at' => 'immutable_datetime',
            'first_received_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime',
        ];
    }
}
