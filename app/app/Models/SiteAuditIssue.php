<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditSeverity;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\SiteAuditIssueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing wrong with one page — or, where `site_audit_page_id` is null, with
 * the site itself. A missing robots.txt belongs to no URL.
 *
 * @property string $id
 * @property string $project_id
 * @property string $site_audit_id
 * @property string|null $site_audit_page_id
 * @property string $check_key
 * @property AuditSeverity $severity
 * @property string $summary
 * @property array<string, mixed> $detail
 */
class SiteAuditIssue extends Model
{
    use BelongsToProject;

    /** @use HasFactory<SiteAuditIssueFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'site_audit_id',
        'site_audit_page_id',
        'check_key',
        'severity',
        'summary',
        'detail',
    ];

    protected $attributes = [
        'detail' => '{}',
    ];

    /**
     * @return BelongsTo<SiteAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(SiteAudit::class, 'site_audit_id');
    }

    /**
     * @return BelongsTo<SiteAuditPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SiteAuditPage::class, 'site_audit_page_id');
    }

    /**
     * Findings about the site rather than about any one page.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSiteWide(Builder $query): void
    {
        $query->whereNull('site_audit_page_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => AuditSeverity::class,
            'detail' => 'array',
        ];
    }
}
