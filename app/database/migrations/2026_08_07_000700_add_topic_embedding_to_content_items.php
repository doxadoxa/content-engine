<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A second vector, for a different question.
 *
 * `embedding` is the article's *body*, written by the corpus index so that one
 * finished piece can find another to link to. This one is the *topic* — the
 * target query, a handful of words — and it answers whether two things are
 * about the same subject before either has been written.
 *
 * They are not interchangeable. Comparing a query against a body puts them far
 * apart whatever the subject, and writing one into the other's column silently
 * breaks internal linking, which is what a first attempt at this did.
 */
return new class extends Migration
{
    private const int DIMENSIONS = 1536;

    public function up(): void
    {
        DB::statement('ALTER TABLE content_items ADD COLUMN topic_embedding vector('.self::DIMENSIONS.')');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE content_items DROP COLUMN topic_embedding');
    }
};
