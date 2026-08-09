<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DatabaseSetupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_suite_runs_against_postgres(): void
    {
        // Guards the decision, not the driver: a well-meaning revert of
        // phpunit.xml to sqlite would make every later phase's pgvector and
        // jsonb behaviour untested while the suite stayed green.
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    #[Test]
    public function the_suite_never_runs_against_the_development_database(): void
    {
        // RefreshDatabase drops every table it finds. Pointed at the wrong
        // database it wipes the running stack's data on the first test — which
        // is what happened here before tests/bootstrap.php existed, because
        // phpunit.xml's force="true" loses to Docker's $_SERVER values.
        $this->assertStringEndsWith('_test', (string) DB::connection()->getDatabaseName());
    }

    #[Test]
    public function pgvector_is_available(): void
    {
        $installed = DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'vector'");

        $this->assertNotNull($installed, 'The vector extension is not installed in the test database.');
    }

    #[Test]
    public function a_vector_column_can_be_created_and_queried(): void
    {
        // The extension existing is not the same as being usable — an
        // extension installed into another schema passes the check above and
        // fails here, which is what a phase-8 migration would hit.
        DB::statement('CREATE TABLE vector_probe (id serial primary key, embedding vector(3))');
        DB::statement("INSERT INTO vector_probe (embedding) VALUES ('[1,2,3]')");

        $nearest = DB::selectOne("SELECT id FROM vector_probe ORDER BY embedding <-> '[1,2,3]' LIMIT 1");

        $this->assertNotNull($nearest);
    }
}
