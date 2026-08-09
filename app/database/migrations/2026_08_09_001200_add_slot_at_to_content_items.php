<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The minute a social slot stands in (§4.3).
 *
 * `scheduled_for` is a `date`, and for an article that is right: an article is
 * due on a day, and the whole planning contour compares it against
 * `Carbon::today()->toDateString()`. A social slot is not due on a day. §4.3
 * puts it inside a window — "слот ставится только туда, где оператор доступен
 * следующие 60–90 минут… пост в 03:00 хуже, чем отсутствие поста" — so the
 * hour and the minute are the entire content of the decision, and a date column
 * cannot hold them.
 *
 * Widening `scheduled_for` was the alternative and it is worse. Every article
 * query in the engine compares that column to a date string; making it a
 * timestamp changes what "due on the 5th" means for rows written by four
 * earlier phases, in a direction nobody would notice until an article was
 * skipped for being due at midnight. So the article calendar keeps its date and
 * the social slot gets its instant.
 *
 * Both are written together by the planner and the date is derived from the
 * instant in the project's timezone, so they cannot disagree: the date is what
 * makes "how many on Tuesday" a group-by, the instant is what the duty check
 * and the publisher read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->timestampTz('slot_at')->nullable();

            // The governor's other count. The ceiling of §4.3 is asked of a
            // week that has not happened yet, so it ranges over `slot_at` the
            // same way the existing index ranges over `published_at`.
            $table->index(['project_id', 'slot_at']);
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'slot_at']);
            $table->dropColumn('slot_at');
        });
    }
};
