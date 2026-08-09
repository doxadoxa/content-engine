<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One auditable turn that produced or refined a monthly proposal.
 *
 * @property string $id
 * @property string $project_id
 * @property string $content_plan_id
 * @property int $proposal_version
 * @property string $role
 * @property string $body
 * @property array<string, mixed> $metadata
 */
class ContentPlanMessage extends Model
{
    use BelongsToProject;
    use HasUlids;

    protected $fillable = [
        'content_plan_id',
        'proposal_version',
        'role',
        'body',
        'metadata',
    ];

    /** @return BelongsTo<ContentPlan, $this> */
    public function contentPlan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'proposal_version' => 'integer',
            'metadata' => 'array',
        ];
    }
}
