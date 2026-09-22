<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $publication_id
 * @property string|null $snapshot_id
 * @property string|null $status
 * @property array<string, mixed> $results
 */
class PagePublicationCheck extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['publication_id', 'snapshot_id', 'status', 'results'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Review evidence is immutable. Create a new record.'));
        static::deleting(fn () => throw new LogicException('Review evidence must be retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['results' => 'array'];
    }
}
