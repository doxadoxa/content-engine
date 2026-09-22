<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $proposal_id
 * @property string|null $revision_id
 * @property string|null $business_fact_id
 * @property string|null $fact_version_id
 * @property-read BusinessFact|null $fact
 * @property-read BusinessFactVersion|null $version
 */
class PageProposalFact extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['proposal_id', 'revision_id', 'business_fact_id', 'fact_version_id'];

    /** @return BelongsTo<BusinessFact, $this> */
    public function fact(): BelongsTo
    {
        return $this->belongsTo(BusinessFact::class, 'business_fact_id');
    }

    /** @return BelongsTo<BusinessFactVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(BusinessFactVersion::class, 'fact_version_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Review evidence is immutable. Create a new record.'));
        static::deleting(fn () => throw new LogicException('Review evidence must be retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }
}
