<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation with the engine.
 *
 * One thread per project rather than one per session, because the person on the
 * other side of it is the project's operator and the thing being discussed is
 * the project. A thread that started over in a new tab would make the assistant
 * a stranger every morning, which is the opposite of the teammate it is
 * supposed to be.
 *
 * **Turns are rows, including the model's tool calls.** The alternative is
 * storing the provider's message blob and re-rendering it, and that fails the
 * first time the provider changes its shape — and it makes "what did the engine
 * actually do when I asked for that" unanswerable in SQL, which is the same
 * question §7 makes the product answer everywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_threads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('project_id');
        });

        Schema::create('assistant_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('assistant_thread_id')->constrained()->cascadeOnDelete();

            // `user`, `assistant`, or `tool`. Not an enum column: the set is
            // small and stable, and a check constraint says the same thing
            // without a migration every time a role is added.
            $table->string('role', 16);

            // What the person or the model said. Empty on a `tool` row, whose
            // content is the two json columns below.
            $table->text('body')->nullable();

            // The call, as it was made and as it came back. Kept apart from the
            // body so a tool row can be rendered as the thing it did — with a
            // link to what it made — rather than as a paragraph about it.
            $table->string('tool_name')->nullable();
            $table->jsonb('tool_arguments')->nullable();
            $table->jsonb('tool_result')->nullable();

            // What the turn cost, on the assistant row that paid for it. Zero
            // rather than null where the provider reported nothing, matching
            // `ModelResponse`: a cost table with holes in it is
            // indistinguishable from a cost table showing cheap turns.
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            $table->timestamps();

            $table->index(['assistant_thread_id', 'created_at']);
        });

        DB::statement(
            "alter table assistant_messages add constraint assistant_messages_role_check
             check (role in ('user', 'assistant', 'tool'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_threads');
    }
};
