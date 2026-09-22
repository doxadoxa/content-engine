<?php

declare(strict_types=1);

namespace App\Models;

use App\Feedback\Measurements\ReadStatus;
use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string $source
 * @property Carbon $window_from
 * @property Carbon $window_to
 * @property ReadStatus $status
 * @property int $row_count
 * @property string|null $reason
 * @property array<string, mixed> $metadata
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class MeasurementRead extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = ['source', 'window_from', 'window_to', 'status', 'row_count', 'reason', 'metadata', 'started_at', 'finished_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => 'string',
            'window_from' => 'date',
            'window_to' => 'date',
            'status' => ReadStatus::class,
            'row_count' => 'integer',
            'reason' => 'string',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
