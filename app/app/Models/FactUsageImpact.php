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
 * @property string $previous_fact_version_id
 * @property string $new_fact_version_id
 * @property string $origin_type
 * @property string $origin_id
 * @property array<string,mixed> $evidence
 */
class FactUsageImpact extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['site_page_id', 'previous_fact_version_id', 'new_fact_version_id', 'origin_type', 'origin_id', 'evidence'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Fact-maintenance evidence is immutable. Record a new check or review.'));
        static::deleting(fn () => throw new \LogicException('Fact-maintenance history is retained.'));
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }
}
