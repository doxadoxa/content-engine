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
 * @property string $sampling_cell_id
 * @property string $full_text
 * @property list<array<string, mixed>> $sections
 * @property list<array<string, mixed>> $citations
 * @property array<string, mixed> $metadata
 * @property string $content_hash
 * @property bool|null $mentioned_in_text
 * @property bool|null $cited_own_site
 * @property string|null $resolved_model
 * @property CarbonImmutable $received_at
 * @property int|null $total_cost_micros
 */
class AiSamplingAnswer extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['sampling_cell_id', 'full_text', 'sections', 'citations', 'metadata', 'content_hash', 'mentioned_in_text', 'cited_own_site', 'resolved_model', 'received_at', 'total_cost_micros'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Sampling evidence is immutable. Record a new version.'));
        static::deleting(fn () => throw new \LogicException('Sampling evidence is retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sections' => 'array', 'citations' => 'array', 'metadata' => 'array', 'received_at' => 'immutable_datetime', 'mentioned_in_text' => 'boolean', 'cited_own_site' => 'boolean', 'total_cost_micros' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
