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
 * @property string $channel_group
 * @property int $sessions
 * @property int $purchases
 * @property int $gross_revenue_micros
 * @property int $refund_micros
 * @property int $net_revenue_micros
 * @property string|null $currency
 */
class PageAnalyticsMetric extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = ['site_page_id', 'measurement_read_id', 'measured_on', 'channel_group', 'sessions', 'purchases', 'gross_revenue_micros', 'refund_micros', 'net_revenue_micros', 'currency'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'measured_on' => 'date',
            'sessions' => 'integer',
            'purchases' => 'integer',
            'gross_revenue_micros' => 'integer',
            'refund_micros' => 'integer',
            'net_revenue_micros' => 'integer',
        ];
    }
}
