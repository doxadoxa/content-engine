<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One counter: how much of one thing a project has used in one period.
 *
 * Unscoped for the reason {@see ProjectSubscription} is: the panel reads these
 * across tenants and the reconciler rebuilds them outside any request. Writes
 * always name the project explicitly, and the unique index on
 * (project, period, metric) is what makes concurrent increments safe.
 *
 * @property string $id
 * @property string $project_id
 * @property Carbon $period_started_at
 * @property string $metric
 * @property int $used
 */
class ProjectUsagePeriod extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id',
        'period_started_at',
        'metric',
        'used',
    ];

    protected $attributes = [
        'used' => 0,
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_started_at' => 'datetime',
            'used' => 'integer',
        ];
    }
}
