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
 * @property CarbonImmutable $period_started_at
 * @property CarbonImmutable $period_ends_at
 * @property int $slot
 * @property string|null $sampling_run_id
 * @property string|null $pipeline_run_id
 * @property CarbonImmutable|null $prompt_attempted_at
 */
class AiSamplingCycle extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['period_started_at', 'period_ends_at', 'slot', 'sampling_run_id', 'pipeline_run_id', 'prompt_attempted_at'];

    protected function casts(): array
    {
        return ['period_started_at' => 'immutable_datetime', 'period_ends_at' => 'immutable_datetime', 'slot' => 'integer', 'prompt_attempted_at' => 'immutable_datetime'];
    }
}
