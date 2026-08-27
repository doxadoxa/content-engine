<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Many conversations per project, each with a name.
 *
 * The first shape was one endless thread per project, on the reasoning that the
 * operator and the engine are always talking about the same business. That is
 * true and it is not the point: a person holds several conversations about one
 * business at once — this month's plan, why Portuguese visibility is zero, what
 * to do about the delivery failures — and a single scroll makes all three
 * harder to return to than none of them.
 *
 * So a thread is a subject, it has a title, and it has a URL. `last_message_at`
 * rather than `updated_at` for ordering, because a list sorted by "when
 * something was last said in it" must not be reshuffled by a rename or by any
 * other write to the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One per project was the whole design; now it is the thing in the way
        // of it. Dropped conditionally because `down()` deliberately does not
        // put it back — it cannot, once a project has several threads — so a
        // rollback followed by a re-migrate would otherwise fail here on a
        // constraint that is already gone.
        if (Schema::hasIndex('assistant_threads', 'assistant_threads_project_id_unique')) {
            Schema::table('assistant_threads', function (Blueprint $table): void {
                $table->dropUnique('assistant_threads_project_id_unique');
            });
        }

        Schema::table('assistant_threads', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('project_id');
            $table->timestamp('last_message_at')->nullable()->after('title');

            $table->index(['project_id', 'last_message_at']);
        });

        // The thread that already exists on this installation keeps its
        // conversation rather than being orphaned by the rename.
        DB::table('assistant_threads')
            ->whereNull('title')
            ->update(['title' => 'Earlier conversation']);
    }

    /**
     * The columns come back; the uniqueness does not.
     *
     * By the time anybody rolls this back a project has several threads, and
     * `unique(project_id)` cannot be recreated over them. Of the three ways
     * out — fail, delete the extra conversations, or leave the constraint off —
     * only the last is both reversible and non-destructive. A rollback that
     * deletes somebody's conversations to satisfy an index is a worse outcome
     * than an index that is missing, and a rollback that simply fails blocks
     * the deploy it exists to unblock.
     *
     * Nothing depends on the constraint: it enforced a product decision that
     * this migration reversed, not an invariant anything reads.
     */
    public function down(): void
    {
        Schema::table('assistant_threads', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'last_message_at']);
            $table->dropColumn(['title', 'last_message_at']);
        });
    }
};
