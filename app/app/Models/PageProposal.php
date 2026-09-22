<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $opportunity_id
 * @property string|null $site_page_id
 * @property string|null $current_revision_id
 * @property string|null $approved_revision_id
 * @property string|null $status
 * @property string|null $invalidation_reason
 * @property string|null $generation_id
 * @property string|null $generation_snapshot_id
 * @property array<string, mixed> $generation_context
 * @property int $created_by
 * @property-read PageOpportunity|null $opportunity
 * @property-read SitePage|null $page
 * @property-read PageProposalRevision|null $currentRevision
 * @property-read PageProposalRevision|null $approvedRevision
 * @property-read PageSnapshot|null $generationSnapshot
 */
class PageProposal extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['opportunity_id', 'site_page_id', 'current_revision_id', 'approved_revision_id', 'status', 'invalidation_reason', 'generation_id', 'generation_snapshot_id', 'generation_context', 'created_by'];

    /** @return BelongsTo<PageOpportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PageOpportunity::class, 'opportunity_id');
    }

    /** @return BelongsTo<SitePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }

    /** @return BelongsTo<PageProposalRevision, $this> */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(PageProposalRevision::class, 'current_revision_id');
    }

    /** @return BelongsTo<PageProposalRevision, $this> */
    public function approvedRevision(): BelongsTo
    {
        return $this->belongsTo(PageProposalRevision::class, 'approved_revision_id');
    }

    /** @return BelongsTo<PageSnapshot, $this> */
    public function generationSnapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'generation_snapshot_id');
    }

    /** @return HasMany<PageProposalRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PageProposalRevision::class, 'proposal_id')->orderByDesc('id');
    }

    /** @return HasMany<PageProposalReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(PageProposalReview::class, 'proposal_id')->orderByDesc('id');
    }

    /** @return HasMany<PagePublication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(PagePublication::class, 'proposal_id')->orderByDesc('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['generation_context' => 'array'];
    }
}
