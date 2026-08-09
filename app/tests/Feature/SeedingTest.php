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
}
