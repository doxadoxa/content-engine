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
 * @property string $proposal_id
 * @property string $revision_id
 * @property string $site_page_id
 * @property string $source_snapshot_id
 * @property string|null $purchase_source_id
 * @property CarbonImmutable $pinned_at
 * @property array<string, mixed> $evidence
 */
class PageChangeBaseline extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['proposal_id', 'revision_id', 'site_page_id', 'source_snapshot_id', 'purchase_source_id', 'pinned_at', 'evidence'];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Change measurement evidence is immutable. Record another observation.'));
        self::deleting(static fn () => throw new LogicException('Change measurement evidence is retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pinned_at' => 'immutable_datetime', 'evidence' => 'array'];
    }
}
