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
 * @property string $sampling_set_id
 * @property string $request_key
 * @property string $purpose
 * @property string $status
 * @property int|null $requested_by
 * @property string|null $recheck_of_run_id
 * @property CarbonImmutable|null $finished_at
 */
class AiSamplingRun extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['sampling_set_id', 'request_key', 'purpose', 'status', 'requested_by', 'recheck_of_run_id', 'finished_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['finished_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
