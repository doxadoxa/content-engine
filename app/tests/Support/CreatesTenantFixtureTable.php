<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the fixture table inside the test's own transaction.
 *
 * Postgres has transactional DDL, so RefreshDatabase rolls this back with
 * everything else and the table never reaches the schema the app migrates.
 * A migration under database/migrations would ship a test-only table to
 * production instead.
 */
trait CreatesTenantFixtureTable
{
    protected function createTenantFixtureTable(): void
    {
        Schema::create('tenanted_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }
}
