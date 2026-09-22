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
 * @property string $claim_id
 * @property string $request_key
 * @property string $action
 * @property int $actor_id
 * @property string $reason
 * @property array<string,mixed> $evidence
 */
class FactMaintenanceReview extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['claim_id', 'request_key', 'action', 'actor_id', 'reason', 'evidence'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Fact-maintenance evidence is immutable. Record a new check or review.'));
        static::deleting(fn () => throw new \LogicException('Fact-maintenance history is retained.'));
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }
}
