<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string $content_plan_id
 * @property Carbon $period_started_at
 * @property Carbon $period_ends_at
 * @property string $timezone
 * @property Carbon|null $plan_counted_at
 */
class ArticlePlanningPeriod extends Model
{
    use BelongsToProject, HasUlids;

    protected $guarded = ['id', 'project_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period_started_at' => 'datetime', 'period_ends_at' => 'datetime', 'plan_counted_at' => 'datetime'];
    }
}
