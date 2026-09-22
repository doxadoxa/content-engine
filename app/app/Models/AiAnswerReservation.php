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
 * @property string $sampling_cell_id
 * @property string $status
 * @property CarbonImmutable $period_started_at
 * @property CarbonImmutable $period_ends_at
 */
class AiAnswerReservation extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['sampling_cell_id', 'period_started_at', 'period_ends_at', 'status', 'attempted_at', 'released_at'];

    protected function casts(): array
    {
        return ['period_started_at' => 'immutable_datetime', 'period_ends_at' => 'immutable_datetime', 'attempted_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }
}
