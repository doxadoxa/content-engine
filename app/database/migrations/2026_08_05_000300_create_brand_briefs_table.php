<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Brand Brief, versioned (§2: "изменение тона — новая версия; старые
 * публикации знают, на какой версии сделаны").
 *
 * Every save writes a row. Nothing here is ever updated in place except the
 * `is_active` flag, because a published article points at the version it was
 * written from and rewriting that row would rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_briefs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // Per project, starting at 1. Not a global sequence: an operator
            // reads "brief v3" and means the third brief of *their* project.
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(false);

            $table->text('positioning')->default('');
            $table->text('audience')->default('');
            $table->text('tone')->default('');
            $table->text('visual_language')->default('');

            // Sets, read whole and never joined on, so json rather than tables.
            $table->json('forbidden_topics');
            $table->json('examples_liked');
            $table->json('examples_disliked');
            $table->json('competitors');

            // Free-text note on why this revision exists. Shown next to the
            // diff in the version history so "why did the tone change in March"
            // has an answer that is not archaeology.
            $table->string('change_note')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'version']);
        });

        // "Активная одна" is a database rule, not a code convention: two active
        // briefs means the compile step picks one at random and half the
        // project's output is written in the wrong voice. A partial unique
        // index says it once, for every writer.
        DB::statement(
            'CREATE UNIQUE INDEX brand_briefs_one_active_per_project
             ON brand_briefs (project_id) WHERE is_active'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_briefs');
    }
};
