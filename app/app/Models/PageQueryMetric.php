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
 * @property string $site_page_id
 * @property string $measurement_read_id
 * @property Carbon $measured_on
 * @property string $query
 * @property string $query_hash
 * @property int $impressions
 * @property int $clicks
 * @property int|null $position_tenths
 */
class PageQueryMetric extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = ['site_page_id', 'measurement_read_id', 'measured_on', 'query', 'query_hash', 'impressions', 'clicks', 'position_tenths'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'measured_on' => 'date',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'position_tenths' => 'integer',
        ];
    }
}
