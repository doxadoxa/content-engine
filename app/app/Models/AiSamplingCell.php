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
 * @property CarbonImmutable $created_at
 * @property string $sampling_run_id
 * @property string $cell_key
 * @property array<string, mixed> $specification
 * @property array<string, mixed>|null $inventory
 * @property CarbonImmutable|null $inventory_checked_at
 * @property string $status
 * @property string|null $reason
 * @property CarbonImmutable|null $attempted_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $pipeline_run_id
 */
class AiSamplingCell extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['sampling_run_id', 'cell_key', 'specification', 'inventory', 'inventory_checked_at', 'status', 'reason', 'attempted_at', 'finished_at', 'pipeline_run_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['specification' => 'array', 'inventory' => 'array', 'inventory_checked_at' => 'immutable_datetime', 'attempted_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
