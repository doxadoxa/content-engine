<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Project;
use App\Support\Tenancy\CrossTenantWriteException;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectScope;
use App\Support\Tenancy\TenantMissingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as owned by a project (tenant).
 *
 * Reads are scoped to the current project, writes are stamped with it, and a
 * write aimed at a different project is refused rather than silently accepted.
 * Doing this in the model rather than at each call site is the point: the query
 * someone forgets to scope is the one that leaks.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToProject
{
    public static function bootBelongsToProject(): void
    {
        static::addGlobalScope(new ProjectScope);

        static::creating(function (Model $model): void {
            $current = app(CurrentProject::class)->id();
            /** @var string|null $assigned */
            $assigned = $model->getAttribute('project_id');

            if ($assigned === null) {
                if ($current === null) {
                    throw TenantMissingException::for($model::class);
                }

                $model->setAttribute('project_id', $current);

                return;
            }

            if ($current !== null && $assigned !== $current) {
                throw CrossTenantWriteException::for($model::class, $assigned, $current);
            }
        });

        // Reassigning an existing row to another tenant is never a legitimate
        // update — it is a mass-assignment bug reaching project_id.
        static::updating(function (Model $model): void {
            if (! $model->isDirty('project_id')) {
                return;
            }

            /** @var string $original */
            $original = $model->getOriginal('project_id');
            /** @var string $assigned */
            $assigned = $model->getAttribute('project_id');

            throw CrossTenantWriteException::for($model::class, $assigned, $original);
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * An unscoped query, for the few places that legitimately span tenants:
     * maintenance commands, cross-project reporting, tests.
     *
     * Named rather than spelled out at the call site so the exceptions are
     * greppable — `acrossProjects` should be rare and every use reviewable.
     *
     * @return Builder<static>
     */
    public static function acrossProjects(): Builder
    {
        return static::query()->withoutGlobalScope(ProjectScope::class);
    }
}
