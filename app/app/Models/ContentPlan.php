<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentPlanStatus;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\ContentPlanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A month of planned units (§3.2). Assembled by the planner in phase 4,
 * approved by an operator in phase 7.
 *
 * @property string $id
 * @property string $project_id
 * @property Carbon $month
 * @property ContentPlanStatus $status
 * @property Carbon|null $approved_at
 */
class ContentPlan extends Model
{
    use BelongsToProject;

    /** @use HasFactory<ContentPlanFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'month',
        'status',
    ];

    /**
     * Sign the plan off. Only a draft can be approved, and approving twice is
     * refused rather than silently re-stamping `approved_at` — the second call
     * is a double-submitted button, and moving the timestamp loses when the
     * decision was actually made.
     */
    public function approve(): self
    {
        if ($this->status !== ContentPlanStatus::Draft) {
            throw new RuntimeException(
                "Only a draft plan can be approved; this one is {$this->status->value}."
            );
        }

        $this->status = ContentPlanStatus::Approved;
        $this->approved_at = now();
        $this->save();

        return $this;
    }

    public function isApproved(): bool
    {
        return $this->status === ContentPlanStatus::Approved;
    }

    /**
     * @return HasMany<ContentItem, $this>
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'status' => ContentPlanStatus::class,
            'approved_at' => 'datetime',
        ];
    }
}
