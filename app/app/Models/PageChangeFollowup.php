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
 * @property string $baseline_id
 * @property string $publication_id
 * @property string $site_page_id
 * @property int $days
 * @property CarbonImmutable $verified_at
 * @property CarbonImmutable $window_from
 * @property CarbonImmutable $window_to
 * @property CarbonImmutable $due_on
 */
class PageChangeFollowup extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['baseline_id', 'publication_id', 'site_page_id', 'days', 'verified_at', 'window_from', 'window_to', 'due_on'];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Change measurement evidence is immutable. Record another observation.'));
        self::deleting(static fn () => throw new LogicException('Change measurement evidence is retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['days' => 'integer', 'verified_at' => 'immutable_datetime', 'window_from' => 'immutable_date', 'window_to' => 'immutable_date', 'due_on' => 'immutable_date'];
    }
}
