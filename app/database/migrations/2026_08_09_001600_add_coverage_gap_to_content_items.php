<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an idea exists, when what caused it was a hole rather than a post (§6).
 *
 * §6's first consequence is that "тема, по которой в Threads есть разговор и
 * отклик, а на сайте нет страницы, уходит в планировщик статей с приоритетом" —
 * the social channel starts driving the content plan. The measurement half of
 * that has been written every night since 12.6 into `project_states.entity_coverage`
 * and read by nothing at all; this column is the other end of it.
 *
 * A column of its own rather than `signal_id`, and the difference is the point.
 * A signal is one post somebody wrote; a coverage gap is a day of conversation
 * against the whole corpus, and frequently there is no single signal to blame —
 * a subject that only ever came up in replies to us lives in `interactions` and
 * never became a signal at all. Borrowing `signal_id` would also have a side
 * effect nobody would find for months: `FeedPlanner` skips any signal that
 * already has a unit, so pinning a gap to a question would silently cancel the
 * article that question was going to get.
 *
 * The counts travel with it because §6's priority *is* the counts — how much
 * conversation there was — and the planner ranks on them. Denormalised on
 * purpose: the snapshot they came from is one row per day and this idea may sit
 * in the pool for a month, so recomputing them at planning time would rank
 * today's idea by a window that has moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            // {entity, signals, interactions, captured_on}. Nullable, and null
            // is the answer for every idea that came from a keyword or a
            // question — this is provenance for one kind of origin, not a
            // field every row fills in.
            $table->jsonb('coverage_gap')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn('coverage_gap');
        });
    }
};
