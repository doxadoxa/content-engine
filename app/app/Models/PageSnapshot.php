<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Editable source and observed public content remain separate from business evidence.
 *
 * @property string $id
 * @property string $project_id
 * @property string $site_page_id
 * @property string $source_kind
 * @property string $source_url
 * @property Carbon $captured_at
 * @property string $revision
 * @property string $content_hash
 * @property array<string, string> $fields
 * @property list<string> $editable_fields
 * @property array<string, mixed> $metadata
 */
class PageSnapshot extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['site_page_id', 'source_kind', 'source_url', 'captured_at', 'revision', 'content_hash', 'fields', 'editable_fields', 'metadata'];

    /** @return BelongsTo<SitePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Page snapshots are immutable. Capture a new snapshot.'));
        static::deleting(fn () => throw new LogicException('Page snapshots are retained as publication evidence.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'fields' => 'array', 'editable_fields' => 'array', 'metadata' => 'array'];
    }
}
