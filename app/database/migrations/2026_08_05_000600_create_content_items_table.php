<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The content unit (§2) — "юнит ≠ статья". One row is one locale of one thing:
 * the unit as a whole is a `locale_group_id`, and its social derivatives are
 * rows pointing at a parent.
 *
 * Two foreign keys here are deliberately NO ACTION rather than RESTRICT, and
 * the difference is not cosmetic. Both mean "you may not delete the referenced
 * row while this one points at it". RESTRICT enforces that immediately, which
 * would also break `DELETE FROM projects` — the cascade removes the parent and
 * its derivatives in one statement, and RESTRICT would fire on the parent
 * before seeing that the children go too. NO ACTION is checked at the end of
 * the statement, so dropping a whole tenant works and dropping a parent out
 * from under its derivatives still fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // An idea outlives the plan it was drafted into: discarding a March
            // plan should not delete the research that fed it.
            $table->foreignUlid('content_plan_id')->nullable()
                ->constrained()->nullOnDelete();

            // Which brief version this was written from — the other half of
            // "старые публикации знают, на какой версии сделаны". Null until a
            // pipeline actually compiles a brief into a prompt (phase 3).
            $table->foreignUlid('brand_brief_id')->nullable()
                ->constrained()->noActionOnDelete();

            // Derivatives (§2). Self-referencing, one level: a LinkedIn post
            // derived from an article, not a post derived from a post. The
            // constraint itself is added below — inside the create block it
            // would be emitted before the primary key it points at exists.
            $table->ulid('parent_id')->nullable();

            // The unit identity. Locale rows of the same thing share this, so
            // it is generated once for the first row and copied to siblings.
            $table->ulid('locale_group_id');
            $table->string('locale', 12);

            $table->string('state')->default('idea');
            $table->string('type');

            $table->string('slug');
            $table->string('title');

            // What the planner aimed this at, and what it must cover to be
            // citable (§1, GEO). Metrics come from Ahrefs in phase 4.
            $table->string('target_query')->nullable();
            $table->json('entities');
            $table->unsignedSmallInteger('topic_difficulty')->nullable();
            $table->unsignedInteger('topic_volume')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // One locale per group. Without it a second Portuguese row of the
            // same unit is legal, and every "give me this unit in PT" query
            // starts having to pick.
            $table->unique(['locale_group_id', 'locale']);

            // A URL identifies one thing per project per language.
            $table->unique(['project_id', 'slug', 'locale']);

            // The daily worker's query: this project's units in this state.
            $table->index(['project_id', 'state']);
            $table->index(['project_id', 'content_plan_id']);
            $table->index('parent_id');
        });

        Schema::table('content_items', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('content_items')
                ->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
