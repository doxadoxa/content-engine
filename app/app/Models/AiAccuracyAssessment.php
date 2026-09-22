<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property CarbonImmutable $created_at
 * @property string $answer_id
 * @property string $scope
 * @property string|null $site_page_id
 * @property string|null $snapshot_id
 * @property string|null $parent_finding_id
 * @property string $request_key
 * @property list<array<string, mixed>> $sections
 * @property list<array<string, mixed>> $fact_versions
 * @property array<string, mixed> $source_metadata
 * @property string $source_hash
 * @property string $status
 * @property array<string, mixed>|null $result
 * @property string|null $pipeline_run_id
 * @property int|null $requested_by
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
class AiAccuracyAssessment extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['answer_id', 'scope', 'site_page_id', 'snapshot_id', 'parent_finding_id', 'request_key', 'sections', 'fact_versions', 'source_metadata', 'source_hash', 'status', 'result', 'pipeline_run_id', 'requested_by', 'started_at', 'finished_at'];

    protected static function booted(): void
    {
        static::updating(function (self $assessment): void {
            if (! in_array($assessment->getOriginal('status'), ['queued', 'running'], true)
                || $assessment->isDirty(['answer_id', 'scope', 'site_page_id', 'snapshot_id', 'parent_finding_id', 'request_key', 'sections', 'fact_versions', 'source_metadata', 'source_hash', 'requested_by'])) {
                throw new LogicException('Assessment inputs and completed results are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Accuracy evidence and review history are retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'sections' => 'array', 'fact_versions' => 'array', 'source_metadata' => 'array', 'result' => 'array', 'requested_by' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
