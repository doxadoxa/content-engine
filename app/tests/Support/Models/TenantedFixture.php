<?php

declare(strict_types=1);

namespace Tests\Support\Models;

use App\Models\Concerns\BelongsToProject;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\CreatesTenantFixtureTable;

/**
 * A stand-in for the tenant-owned models that arrive in phase 2.
 *
 * The trait is infrastructure with no production consumer yet, and testing it
 * through whichever model happens to use it first would tie the guarantees of
 * the trait to the shape of that model. Its table is created per test by
 * {@see CreatesTenantFixtureTable}.
 *
 * @property string $id
 * @property string $project_id
 * @property string $title
 */
class TenantedFixture extends Model
{
    use BelongsToProject;

    protected $table = 'tenanted_fixtures';

    protected $fillable = ['title', 'project_id'];
}
