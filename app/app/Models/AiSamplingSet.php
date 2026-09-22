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
 * @property int $version
 * @property array<string, mixed> $configuration
 * @property string $configuration_hash
 * @property int|null $created_by
 * @property string $change_reason
 */
class AiSamplingSet extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['version', 'configuration', 'configuration_hash', 'created_by', 'change_reason'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Sampling evidence is immutable. Record a new version.'));
        static::deleting(fn () => throw new \LogicException('Sampling evidence is retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'configuration' => 'array', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
