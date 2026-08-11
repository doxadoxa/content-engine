<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetRole;
use App\Enums\AssetSource;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An image belonging to a unit (§2): the hero, or an inline image anchored to
 * the section it follows.
 *
 * @property string $id
 * @property string $project_id
 * @property string $content_item_id
 * @property AssetRole $role
 * @property AssetSource $source
 * @property string|null $anchor
 * @property string $disk
 * @property string $path
 * @property string $alt
 * @property int|null $width
 * @property int|null $height
 * @property Carbon|null $superseded_at set when a rewrite retired it
 */
class Asset extends Model
{
    use BelongsToProject;

    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'content_item_id',
        'role',
        'source',
        'anchor',
        'disk',
        'path',
        'alt',
        'width',
        'height',
        'superseded_at',
    ];

    /**
     * @return BelongsTo<ContentItem, $this>
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function isHero(): bool
    {
        return $this->role === AssetRole::Hero;
    }

    /** The address the publish payload carries. */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AssetRole::class,
            'source' => AssetSource::class,
            'width' => 'integer',
            'height' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }
}
