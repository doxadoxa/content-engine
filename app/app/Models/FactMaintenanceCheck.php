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
 * @property Carbon $created_at
 * @property string $site_page_id
 * @property string $request_key
 * @property array<string,mixed> $specification
 * @property int $requested_by
 * @property string|null $source_snapshot_id
 * @property string|null $pipeline_run_id
 * @property string $status
 * @property string|null $reason
 * @property Carbon|null $attempted_at
 * @property Carbon|null $finished_at
 */
class FactMaintenanceCheck extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['site_page_id', 'request_key', 'specification', 'requested_by', 'source_snapshot_id', 'pipeline_run_id', 'status', 'reason', 'attempted_at', 'finished_at'];

    protected static function booted(): void
    {
        static::updating(function (self $check): void {
            if ($check->isDirty(['site_page_id', 'request_key', 'specification', 'requested_by'])
                || ($check->getOriginal('source_snapshot_id') !== null && $check->isDirty('source_snapshot_id'))) {
                throw new \LogicException('Check inputs and pinned source cannot be rewritten. Start a new explicit check.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Fact-maintenance attempts are retained.'));
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['specification' => 'array', 'attempted_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
