<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Internal linking on embeddings (§8.4), and the derivative tree's inheritance.
 *
 * pgvector was enabled in phase 1 with no consumer, on the reasoning that
 * adding it later means a migration on a live database. This is the consumer.
 */
return new class extends Migration
{
    /** text-embedding-3-small. Changing the model means a new column, not a cast. */
    private const int DIMENSIONS = 1536;

    public function up(): void
    {
        // Raw SQL: `vector` is not a type the schema builder knows, and the
        // dimension is part of it rather than a modifier.
        DB::statement('ALTER TABLE content_items ADD COLUMN embedding vector('.self::DIMENSIONS.')');

        Schema::table('content_items', function (Blueprint $table): void {
            // Chosen by nearest neighbour over the corpus, not by matching
            // words (§8.4, exit criterion 3). Inherited by derivatives, which
            // is what makes a social post point at the same places the article
            // does (§8.1).
            $table->json('internal_links')->default('[]');

            // Which channel a derivative was written for. Null on an article.
            $table->string('channel_type')->nullable();
        });

        // IVFFlat needs rows to train on and the table is empty, so the index
        // is deliberately not created here. At this corpus size an exact scan
        // is faster anyway; the index arrives with the volume that needs it.
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn(['internal_links', 'channel_type']);
        });

        DB::statement('ALTER TABLE content_items DROP COLUMN IF EXISTS embedding');
    }
};
