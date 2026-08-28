<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeedingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeding_twice_changes_nothing(): void
    {
        // The app container seeds on every boot, so a seeder that is not
        // idempotent turns `docker compose restart` into a failed container.
        $this->seed(DatabaseSeeder::class);

        $first = User::query()->firstOrFail();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->count());
        $this->assertSame($first->getKey(), User::query()->firstOrFail()->getKey());
    }

    #[Test]
    public function seeding_creates_no_projects(): void
    {
        $this->seed(DatabaseSeeder::class);

        // A project has to come from onboarding: it is only a real project once
        // a site has been read and a brief confirmed, and a seeded row would
        // look identical to one that had been while having none of it.
        $this->assertSame(0, Project::query()->count());
    }

    #[Test]
    public function the_seeded_operator_can_sign_in(): void
    {
        $this->seed(DatabaseSeeder::class);

        $operator = User::query()->firstOrFail();

        $this->assertNotNull($operator->email_verified_at);
    }

    #[Test]
    public function a_fresh_installation_has_somebody_who_can_open_the_panel(): void
    {
        // Migrations run before this seeder on a fresh container, so the pass
        // that grants `is_admin` to the Horizon allow-list finds no accounts —
        // and the one it then creates has the column's default. Without this,
        // `/admin` answers 404 for every account on the installation and
        // nothing is able to change that.
        $this->seed(DatabaseSeeder::class);

        $account = User::query()
            ->where('email', config('seeding.admin.email'))
            ->sole();

        $this->assertTrue($account->is_admin);
        $this->actingAs($account)->get('/admin')->assertOk();
    }

    #[Test]
    public function seeding_again_does_not_re_grant_a_flag_somebody_removed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $account = User::query()->where('email', config('seeding.admin.email'))->sole();

        // Somebody else runs the service now.
        User::factory()->create(['is_admin' => true]);
        $account->forceFill(['is_admin' => false])->save();

        $this->seed(DatabaseSeeder::class);

        // There is a way in, so the seeder has no business making a second one.
        $this->assertFalse($account->fresh()?->is_admin);
    }
}
