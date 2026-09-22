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
 * @property string $id
 * @property string $project_id
 * @property string $business_fact_id
 * @property int $version
 * @property string $statement
 * @property string|null $source_url
 * @property string $source_note
 * @property string $status
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $review_due_at
 * @property-read User|null $confirmer
 * @property int|null $created_by
 */
class BusinessFactVersion extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['business_fact_id', 'version', 'statement', 'source_url', 'source_note', 'status', 'confirmed_by', 'confirmed_at', 'review_due_at', 'created_by'];

    public function isUsable(): bool
    {
        return $this->status === 'confirmed' && $this->confirmed_at !== null
            && $this->confirmed_by !== null && $this->review_due_at !== null && $this->review_due_at->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<BusinessFact, $this> */
    public function fact(): BelongsTo
    {
        return $this->belongsTo(BusinessFact::class, 'business_fact_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fact versions are immutable. Record a new version.'));
        static::deleting(fn () => throw new LogicException('Fact versions are retained as evidence.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'confirmed_at' => 'datetime', 'review_due_at' => 'datetime'];
    }
}
