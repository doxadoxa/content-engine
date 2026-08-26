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
        Schema::table('assistant_threads', function (Blueprint $table): void {
            // One per project was the whole design; now it is the thing in the
            // way of it.
            $table->dropUnique('assistant_threads_project_id_unique');

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

    public function down(): void
    {
        Schema::table('assistant_threads', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'last_message_at']);
            $table->dropColumn(['title', 'last_message_at']);
            $table->unique('project_id');
        });
    }
};
