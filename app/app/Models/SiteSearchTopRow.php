<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One of the property's top queries or pages for one 28-day period.
 *
 * Totals for the period, not per day: this is Google's own top-N list, and a
 * page that is in the top 250 over 28 days is not in any daily top 250 often
 * enough for the days to add back up to it.
 *
 * @property string $id
 * @property string $project_id
 * @property string $measurement_read_id
 * @property string $kind `query` or `page`
 * @property string $period `current` or `previous`
 * @property Carbon $window_from
 * @property Carbon $window_to
 * @property string $value
 * @property string $value_hash
 * @property int $rank
 * @property int $clicks
 * @property int $impressions
 * @property int|null $position_tenths
 */
class SiteSearchTopRow extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = ['measurement_read_id', 'kind', 'period', 'window_from', 'window_to', 'value', 'value_hash', 'rank', 'clicks', 'impressions', 'position_tenths'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'window_from' => 'date',
            'window_to' => 'date',
            'rank' => 'integer',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'position_tenths' => 'integer',
        ];
    }
}
