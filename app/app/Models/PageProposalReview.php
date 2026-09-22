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
 * @property int $actor_id
 * @property string|null $action
 * @property string|null $reason
 * @property int $active_seconds
 * @property array<string, mixed> $corrections
 * @property-read User|null $actor
 */
class PageProposalReview extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['proposal_id', 'revision_id', 'actor_id', 'action', 'reason', 'active_seconds', 'corrections'];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Review evidence is immutable. Create a new record.'));
        static::deleting(fn () => throw new LogicException('Review evidence must be retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active_seconds' => 'integer', 'corrections' => 'array'];
    }
}
