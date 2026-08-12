<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\SiteAuditStarter;
use App\Models\Concerns\BelongsToProject;
use Database\Factories\SiteAuditFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One sweep of the project's own site.
 *
 * The header row: the scores, the counts, and the site-level checks. What was
 * looked at is on {@see SiteAuditPage} and what was wrong with it on
 * {@see SiteAuditIssue}, and the counts here are recomputed from those rows
 * rather than incremented as the sweep goes — three steps write findings in
 * parallel, and a sum of rows cannot drift from the rows it sums. The same
 * reasoning, and the same shape, as {@see PipelineRun::rollUpTotals()}.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $pipeline_run_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $health_score
 * @property int|null $seo_score
 * @property int|null $geo_score
 * @property int|null $speed_score
 * @property int $pages_crawled
 * @property int $issues_found
 * @property array<string, mixed> $site_checks
 * @property string|null $fix_plan
 * @property Carbon|null $fix_plan_written_at
 * @property Carbon $created_at
 */
class SiteAudit extends Model
{
    use BelongsToProject;

    /** @use HasFactory<SiteAuditFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'pipeline_run_id',
        'started_at',
        'finished_at',
        'health_score',
        'seo_score',
        'geo_score',
        'speed_score',
        'pages_crawled',
        'issues_found',
        'site_checks',
        'fix_plan',
        'fix_plan_written_at',
    ];

    protected $attributes = [
        'site_checks' => '{}',
    ];

    /**
     * @return HasMany<SiteAuditPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(SiteAuditPage::class);
    }

    /**
     * @return HasMany<SiteAuditIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(SiteAuditIssue::class);
    }

    /**
     * The newest sweep that produced numbers, or null before the first one.
     *
     * Finished only. A sweep half way through has crawled some of the site and
     * scored none of it, and showing its empty totals next to the previous
     * week's would read as the site having collapsed overnight. The screen
     * renders the last complete answer and says separately that a new one is
     * running — see {@see scopeInFlight()}.
     *
     * `newest()` rather than `latest()`, which every other model in this
     * application inherits from the query builder and which returns a Builder
     * ordered by `created_at`. Shadowing it here with a method that returns a
     * model — and a *filtered* one at that — would make `SiteAudit::latest()`
     * mean something different from `Project::latest()` at a glance, which is
     * the sort of thing nobody reads carefully twice.
     */
    public static function newest(): ?self
    {
        return self::query()->whereNotNull('finished_at')->orderByDesc('created_at')->first();
    }

    /**
     * Sweeps that have not finished yet.
     *
     * Deliberately not bounded by a timeout, unlike {@see
     * PipelineRun::scopeInFlight()}: this row is finished by its own last step,
     * so a run that dies leaves it open forever and the button would never come
     * back. The callers that need liveness ask the run — see
     * {@see SiteAuditStarter}.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereNull('finished_at');
    }

    /** True once the sweep has been scored. */
    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /**
     * Recount the totals from the rows the steps wrote.
     *
     * @return $this
     */
    public function rollUpTotals(): self
    {
        $this->forceFill([
            'pages_crawled' => $this->pages()->count(),
            'issues_found' => $this->issues()->count(),
        ])->save();

        return $this;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'fix_plan_written_at' => 'datetime',
            'health_score' => 'integer',
            'seo_score' => 'integer',
            'geo_score' => 'integer',
            'speed_score' => 'integer',
            'pages_crawled' => 'integer',
            'issues_found' => 'integer',
            'site_checks' => 'array',
        ];
    }
}
