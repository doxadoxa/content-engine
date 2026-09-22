<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string|null $current_version_id
 * @property-read BusinessFactVersion|null $currentVersion
 */
class BusinessFact extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['name'];

    /** @return BelongsTo<BusinessFactVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(BusinessFactVersion::class, 'current_version_id');
    }

    /** @return HasMany<BusinessFactVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(BusinessFactVersion::class)->orderByDesc('version');
    }
}
