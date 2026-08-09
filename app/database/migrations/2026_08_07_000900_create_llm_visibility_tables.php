<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the assistants say about a brand (§9.3, extended into GEO).
 *
 * Two tables and not one. A prompt is the instrument — it is written once and
 * asked repeatedly, and its whole value is that it does not change between
 * measurements. An answer is one reading of that instrument, on one day, from
 * one assistant. Folding them together would mean either rewriting the prompt
 * on every run or storing it again for every answer, and the first destroys the
 * trend line while the second makes "which prompts do we monitor" a query with
 * a DISTINCT in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_prompts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            $table->string('text', 500);

            // The reason this table exists at all rather than a json column on
            // projects. A brand is visible or invisible once per language: a
            // Lisbon business answering in Portuguese, English and Russian is
            // three separate measurements, and a score computed only in the
            // market's own language reads 0% while customers arrive from an
            // assistant answering in another.
            $table->string('locale', 12);

            // buying / comparison / learning — what a person typing this wants.
            // Kept because a brand invisible on buying-intent prompts has a
            // different problem from one invisible on explainers, and a single
            // percentage hides which.
            $table->string('intent', 32);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // The same question in two languages is two prompts; the same
            // question twice in one language is a duplicate that would weight
            // the score toward whatever it happens to ask.
            $table->unique(['project_id', 'locale', 'text']);
            $table->index(['project_id', 'is_active']);
        });

        Schema::create('llm_visibility_answers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('llm_prompt_id')->constrained()->cascadeOnDelete();

            $table->string('platform', 32);
            $table->string('model', 64)->nullable();
            $table->date('asked_on');

            // Nullable, not false. "The assistant declined to answer" and "it
            // answered and did not mention us" are different facts, and only
            // the second belongs in the denominator of a visibility score.
            $table->boolean('mentioned')->nullable();

            // Enough of the answer to see why it scored as it did, without
            // storing every assistant's full reply for every prompt forever.
            $table->text('excerpt')->nullable();

            $table->jsonb('citations')->default('[]');
            $table->jsonb('brands')->default('[]');

            // What the third-party model charged. A measurement whose own cost
            // is invisible is one nobody can decide to run less often.
            $table->decimal('money_spent', 10, 6)->default(0);

            $table->timestamps();

            // One reading per prompt per assistant per day. Re-running a day
            // replaces rather than doubles — an engine tick that overlaps the
            // previous one must not count the same answer twice.
            $table->unique(['llm_prompt_id', 'platform', 'asked_on']);
            $table->index(['project_id', 'asked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_visibility_answers');
        Schema::dropIfExists('llm_prompts');
    }
};
