<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Project;
use App\Support\Tenancy\CrossTenantWriteException;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\TenantMissingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTenantFixtureTable;
use Tests\Support\Models\TenantedFixture;
use Tests\TestCase;

/**
 * The isolation guarantee of phase 1: two projects in one database never see
 * each other's rows, and nothing can write across the boundary by accident.
 */
final class ProjectScopeTest extends TestCase
{
    use CreatesTenantFixtureTable;
    use RefreshDatabase;

    private Project $alpha;

    private Project $beta;

    private CurrentProject $currentProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenantFixtureTable();

        $this->alpha = Project::factory()->create(['name' => 'Alpha']);
        $this->beta = Project::factory()->create(['name' => 'Beta']);
        $this->currentProject = app(CurrentProject::class);
    }

    #[Test]
    public function it_returns_only_rows_of_the_current_project(): void
    {
        $this->currentProject->run($this->alpha, fn () => TenantedFixture::create(['title' => 'alpha note']));
        $this->currentProject->run($this->beta, fn () => TenantedFixture::create(['title' => 'beta note']));

        $seen = $this->currentProject->run($this->alpha, fn () => TenantedFixture::query()->pluck('title')->all());

        $this->assertSame(['alpha note'], $seen);
    }

    #[Test]
    public function it_hides_another_projects_row_from_find(): void
    {
        $betaRow = $this->currentProject->run($this->beta, fn () => TenantedFixture::create(['title' => 'beta note']));

        $found = $this->currentProject->run($this->alpha, fn () => TenantedFixture::find($betaRow->getKey()));

        $this->assertNull($found);
    }

    #[Test]
    public function it_matches_nothing_when_no_project_is_current(): void
    {
        $this->currentProject->run($this->alpha, fn () => TenantedFixture::create(['title' => 'alpha note']));

        $this->currentProject->forget();

        // Fails closed. The opposite default — returning every row when the
        // tenant was never set — is the one that leaks silently.
        $this->assertSame(0, TenantedFixture::query()->count());
    }

    #[Test]
    public function it_stamps_new_rows_with_the_current_project(): void
    {
        $row = $this->currentProject->run($this->alpha, fn () => TenantedFixture::create(['title' => 'alpha note']));

        $this->assertSame($this->alpha->getKey(), $row->project_id);
    }

    #[Test]
    public function it_refuses_to_create_a_row_for_another_project(): void
    {
        $this->expectException(CrossTenantWriteException::class);

        $this->currentProject->run($this->alpha, fn () => TenantedFixture::create([
            'title' => 'smuggled',
            'project_id' => $this->beta->getKey(),
        ]));
    }

    #[Test]
    public function it_refuses_to_create_a_row_with_no_project_at_all(): void
    {
        $this->currentProject->forget();

        $this->expectException(TenantMissingException::class);

        TenantedFixture::create(['title' => 'orphan']);
    }

    #[Test]
    public function it_refuses_to_move_an_existing_row_to_another_project(): void
    {
        $row = $this->currentProject->run($this->alpha, fn () => TenantedFixture::create(['title' => 'alpha note']));

        $this->expectException(CrossTenantWriteException::class);

        $this->currentProject->run($this->alpha, function () use ($row): void {
            $row->update(['project_id' => $this->beta->getKey()]);
        });
    }

    #[Test]
    public function it_allows_an_explicit_project_when_none_is_current(): void
    {
        // Seeders and maintenance commands run without a tenant and say which
        // project they mean. That is legitimate; a silent null is not.
        $row = TenantedFixture::create([
            'title' => 'seeded',
            'project_id' => $this->beta->getKey(),
        ]);

        $this->assertSame($this->beta->getKey(), $row->project_id);
    }

    #[Test]
    public function across_projects_sees_every_tenant(): void
    {
        $this->currentProject->run($this->alpha, fn () => TenantedFixture::create(['title' => 'alpha note']));
        $this->currentProject->run($this->beta, fn () => TenantedFixture::create(['title' => 'beta note']));

        $this->assertSame(2, TenantedFixture::acrossProjects()->count());
    }

    #[Test]
    public function it_restores_the_previous_project_when_the_callback_throws(): void
    {
        $this->currentProject->set($this->alpha);

        try {
            $this->currentProject->run($this->beta, function (): void {
                throw new \RuntimeException('step failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($this->alpha->getKey(), $this->currentProject->id());
    }
}
