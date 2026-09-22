<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $proposal_id
 * @property string|null $revision_id
 * @property string|null $site_page_id
 * @property string|null $delivery_id
 * @property string|null $mode
 * @property string|null $status
 * @property int $authorized_by
 * @property Carbon|null $authorized_at
 * @property int $applied_by
 * @property string|null $applied_by_name
 * @property Carbon|null $applied_at
 * @property string|null $application_note
 * @property Carbon|null $recovered_at
 * @property Carbon|null $verified_at
 * @property string|null $verification_snapshot_id
 * @property array<string, mixed> $verification_results
 * @property-read PageProposal|null $proposal
 * @property-read PageProposalRevision|null $revision
 * @property-read SitePage|null $page
 * @property-read PageSnapshot|null $verificationSnapshot
 */
class PagePublication extends Model
{
    use BelongsToProject, HasUlids;

    protected $fillable = ['proposal_id', 'revision_id', 'site_page_id', 'delivery_id', 'mode', 'status', 'authorized_by', 'authorized_at', 'applied_by', 'applied_by_name', 'applied_at', 'application_note', 'verified_at', 'verification_snapshot_id', 'verification_results', 'recovered_at'];

    /** @return BelongsTo<PageProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(PageProposal::class, 'proposal_id');
    }

    /** @return BelongsTo<PageProposalRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(PageProposalRevision::class, 'revision_id');
    }

    /** @return BelongsTo<SitePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }

    /** @return BelongsTo<PageSnapshot, $this> */
    public function verificationSnapshot(): BelongsTo
    {
        return $this->belongsTo(PageSnapshot::class, 'verification_snapshot_id');
    }

    /** @return HasMany<PagePublicationCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(PagePublicationCheck::class, 'publication_id')->orderByDesc('id');
    }

    /** @return HasMany<PagePublicationOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(PagePublicationOperation::class, 'publication_id')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['authorized_at' => 'datetime', 'applied_at' => 'datetime', 'recovered_at' => 'datetime', 'verified_at' => 'datetime', 'verification_results' => 'array'];
    }
}
