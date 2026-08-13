<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\PageFacts;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\SiteAuditPageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One page as one sweep found it.
 *
 * `facts` is the whole reason the crawl and the inspection are separate steps:
 * the page is fetched once and reduced to what the checks actually read — its
 * title, its canonical, its headings, the types it declares, its images and its
 * internal links. Nothing afterwards touches the network, so a check can be
 * added and the sweep re-scored without asking somebody else's server again.
 *
 * @property string $id
 * @property string $project_id
 * @property string $site_audit_id
 * @property string $url
 * @property int|null $status_code
 * @property int|null $score
 * @property int $issues_count
 * @property int|null $response_ms
 * @property int|null $html_bytes
 * @property array<string, mixed> $facts
 * @property array<string, mixed>|null $speed
 */
class SiteAuditPage extends Model
{
    use BelongsToProject;

    /** @use HasFactory<SiteAuditPageFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'site_audit_id',
        'url',
        'status_code',
        'score',
        'issues_count',
        'response_ms',
        'html_bytes',
        'facts',
        'speed',
    ];

    protected $attributes = [
        'facts' => '{}',
    ];

    /**
     * @return BelongsTo<SiteAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(SiteAudit::class, 'site_audit_id');
    }

    /**
     * @return HasMany<SiteAuditIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(SiteAuditIssue::class);
    }

    /** The stored snapshot, back as the object the checks read. */
    public function facts(): PageFacts
    {
        return PageFacts::fromArray([
            ...$this->facts,
            'url' => $this->url,
            'status_code' => $this->status_code,
            'response_ms' => $this->response_ms,
            'html_bytes' => $this->html_bytes,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'score' => 'integer',
            'issues_count' => 'integer',
            'response_ms' => 'integer',
            'html_bytes' => 'integer',
            'facts' => 'array',
            'speed' => 'array',
        ];
    }
}
