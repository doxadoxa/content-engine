<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property int $actor_id
 * @property string $request_id
 * @property string $fingerprint
 * @property string $category
 * @property int $minutes
 * @property int|null $hourly_usd_cents
 * @property Carbon $happened_at
 * @property string $note
 * @property string|null $supersedes_id
 * @property-read ServiceEffortEntry|null $replacement
 */
class ServiceEffortEntry extends Model
{
    use BelongsToProject, HasUlids;

    protected $guarded = ['id', 'project_id'];

    /** @return HasOne<ServiceEffortEntry, $this> */
    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Record a correction; preserve the original effort entry.'));
        self::deleting(fn () => throw new LogicException('Record a zero-minute correction to void an effort entry.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['minutes' => 'integer', 'hourly_usd_cents' => 'integer', 'happened_at' => 'datetime'];
    }
}
