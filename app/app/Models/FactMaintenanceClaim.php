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
 * @property string $check_id
 * @property string $site_page_id
 * @property string $source_snapshot_id
 * @property array<string,mixed> $source
 * @property string $exact_quote
 * @property int $start_codepoint
 * @property int $end_codepoint
 * @property string $relation
 * @property string|null $fact_version_id
 * @property string $reason
 * @property list<string> $reference_ids
 */
class FactMaintenanceClaim extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['check_id', 'site_page_id', 'source_snapshot_id', 'source', 'exact_quote', 'start_codepoint', 'end_codepoint', 'relation', 'fact_version_id', 'reason', 'reference_ids'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Fact-maintenance evidence is immutable. Record a new check or review.'));
        static::deleting(fn () => throw new \LogicException('Fact-maintenance history is retained.'));
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['source' => 'array', 'reference_ids' => 'array', 'start_codepoint' => 'integer', 'end_codepoint' => 'integer'];
    }
}
