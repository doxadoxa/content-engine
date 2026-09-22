<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property string $publication_id
 * @property string $site_page_id
 * @property string $decision
 * @property string $reason
 * @property string|null $next_opportunity_id
 * @property array<string,mixed> $evidence
 */
class PageOutcomeReview extends Model
{
    use BelongsToProject, HasUlids;

    protected $guarded = ['project_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Outcome decisions are immutable. Record a new decision.'));
        static::deleting(fn () => throw new LogicException('Outcome decisions are retained.'));
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }
}
