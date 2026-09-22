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
 * @property string $assessment_id
 * @property string $relation
 * @property string|null $fact_version_id
 * @property array<string, mixed> $evidence
 */
class AiAccuracyFinding extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['assessment_id', 'relation', 'fact_version_id', 'evidence'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('This accuracy evidence is immutable. Record a new event.'));
        static::deleting(fn () => throw new LogicException('Accuracy evidence and review history are retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'evidence' => 'array'];
    }
}
