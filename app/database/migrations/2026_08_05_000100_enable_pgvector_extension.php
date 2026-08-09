<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pgvector, for the corpus embeddings that internal linking and topic
 * deduplication are built on (§9 of the spec).
 *
 * Enabled in phase 1 rather than in the phase that first stores a vector,
 * because an extension is an environment fact: discovering in phase 8 that
 * production's Postgres cannot create it is a migration that fails on deploy,
 * not a feature that arrives late.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        // Deliberately not dropped: the extension may be shared with other
        // schemas in the same database, and dropping it would take their
        // columns with it.
    }
};
