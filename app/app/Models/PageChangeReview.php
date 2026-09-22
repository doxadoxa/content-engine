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
 * @property string $followup_id
 * @property CarbonImmutable $captured_at
 * @property string $status
 * @property array<string, mixed> $evidence
 * @property string $evidence_hash
 */
class PageChangeReview extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['followup_id', 'captured_at', 'status', 'evidence', 'evidence_hash'];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Change measurement evidence is immutable. Record another observation.'));
        self::deleting(static fn () => throw new LogicException('Change measurement evidence is retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime', 'evidence' => 'array'];
    }
}
