<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $proposal_id
 * @property string|null $site_page_id
 * @property string|null $editable_snapshot_id
 * @property array<string,mixed>|null $compiled_patch
 * @property-read PageSnapshot|null $editableSnapshot
 * @property string|null $source_snapshot_id
 * @property int $number
 * @property string|null $canonical_url
 * @property string|null $locale
 * @property list<array<string, mixed>> $changes
 * @property string|null $patch_hash
 * @property array<string, mixed> $evidence_snapshot
 * @property array<string, mixed> $measurement_plan
 * @property list<string> $missing_facts
 * @property string|null $no_change_reason
 * @property string|null $revision_reason
 * @property int $created_by
 * @property string|null $pipeline_run_id
 * @property-read PageProposal|null $proposal
 * @property-read PageSnapshot|null $sourceSnapshot
 */
class PageProposalRevision extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['proposal_id', 'site_page_id', 'source_snapshot_id', 'editable_snapshot_id', 'compiled_patch', 'number', 'canonical_url', 'locale', 'changes', 'patch_hash', 'evidence_snapshot', 'measurement_plan', 'missing_facts', 'no_change_reason', 'revision_reason', 'created_by', 'pipeline_run_id'];

    /** @return BelongsTo<PageProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(PageProposal::class, 'proposal_id');
    }

    /** @return BelongsTo<PageSnapshot, $this> */
    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'source_snapshot_id');
    }

    /** @return BelongsTo<PageSnapshot, $this> */
    public function editableSnapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'editable_snapshot_id');
    }

    /** @return HasMany<PageProposalFact, $this> */
    public function facts(): HasMany
    {
        return $this->hasMany(PageProposalFact::class, 'revision_id')->orderByDesc('id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Review evidence is immutable. Create a new record.'));
        static::deleting(fn () => throw new LogicException('Review evidence must be retained.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['number' => 'integer', 'compiled_patch' => 'array', 'changes' => 'array', 'evidence_snapshot' => 'array', 'measurement_plan' => 'array', 'missing_facts' => 'array'];
    }
}
