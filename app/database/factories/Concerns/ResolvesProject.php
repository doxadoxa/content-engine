<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * How a tenant-owned factory decides which project it is building for.
 *
 * Inside `CurrentProject::run()` it uses that project, which is what makes
 * `ContentItem::factory()->create()` legal there — `BelongsToProject` refuses a
 * write aimed at any other tenant, so a factory that always minted a fresh
 * project would throw in exactly the tests that set one up.
 *
 * Outside a tenant it makes one, so a factory still stands alone.
 *
 * Use `->recycle($project)` when several factories in one test must land in the
 * same project without a `run()` around them.
 */
trait ResolvesProject
{
    /**
     * @return string|Factory<Project>
     */
    protected function resolveProject(): string|Factory
    {
        return app(CurrentProject::class)->id() ?? Project::factory();
    }
}
