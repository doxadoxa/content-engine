<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $tracked_pages
 * @property int $open_count
 * @property list<string> $notes
 * @property Carbon $created_at
 */
class PageOpportunityScan extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['tracked_pages', 'open_count', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tracked_pages' => 'integer', 'open_count' => 'integer', 'notes' => 'array'];
    }
}
