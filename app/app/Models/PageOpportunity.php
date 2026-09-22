<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string $site_page_id
 * @property string $kind
 * @property string $diagnosed_issue
 * @property string $suggested_scope
 * @property array<string, mixed> $evidence_snapshot
 * @property string $confidence
 * @property string $effort
 * @property array<string, mixed> $ranking_factors
 * @property list<string> $missing_fact_questions
 * @property list<string> $overlap_page_ids
 * @property string $status
 * @property string|null $dismissal_reason
 * @property string $fingerprint
 * @property Carbon $diagnosed_at
 * @property-read SitePage $page
 */
class PageOpportunity extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['site_page_id', 'kind', 'diagnosed_issue', 'suggested_scope', 'evidence_snapshot', 'confidence', 'effort', 'ranking_factors', 'missing_fact_questions', 'overlap_page_ids', 'status', 'dismissal_reason', 'fingerprint', 'diagnosed_at'];

    /** @return BelongsTo<SitePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'evidence_snapshot' => 'array', 'ranking_factors' => 'array',
            'missing_fact_questions' => 'array', 'overlap_page_ids' => 'array',
            'diagnosed_at' => 'datetime',
        ];
    }
}
