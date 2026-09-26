<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Pacific day of the whole Search Console property.
 *
 * @property string $id
 * @property string $project_id
 * @property string $measurement_read_id
 * @property Carbon $measured_on
 * @property int $clicks
 * @property int $impressions
 * @property int|null $position_tenths
 */
class SiteSearchDay extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = ['measurement_read_id', 'measured_on', 'clicks', 'impressions', 'position_tenths'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'measured_on' => 'date',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'position_tenths' => 'integer',
        ];
    }
}
