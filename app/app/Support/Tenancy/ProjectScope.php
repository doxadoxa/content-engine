<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the current project.
 *
 * Fails closed: with no current project the query matches nothing rather than
 * everything. The opposite default is the one that leaks — a forgotten
 * `CurrentProject::set()` in a console command or a job would silently return
 * every tenant's rows and look like it worked.
 *
 * Code that legitimately spans tenants (migrations, cross-project reports)
 * opts out explicitly with {@see BelongsToProject::acrossProjects()}.
 *
 * @implements Scope<Model>
 */
final class ProjectScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $projectId = app(CurrentProject::class)->id();

        $column = $model->qualifyColumn('project_id');

        $projectId === null
            ? $builder->whereRaw('1 = 0')
            : $builder->where($column, $projectId);
    }
}
