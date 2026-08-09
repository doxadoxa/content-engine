<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One week of the governor's decision, written down (§4.3, §7).
 *
 * This table exists for one sentence of §7: "чего движок делать не стал и
 * почему — пустой слот с причиной. Последняя строка обязательна. Молчащий
 * автомат неотличим от сломанного." A planning run that decides to publish
 * nothing is the correct outcome and is indistinguishable from a crashed
 * scheduler unless the reason is stored somewhere a screen can read.
 *
 * It is also where the floor's alert lives, and the three alternatives were all
 * considered:
 *
 * **A `project_states` column.** Wrong grain and wrong owner. That table is a
 * *daily measurement* snapshot owned by 12.6's sweep, keyed on `captured_on`,
 * where null means "not measured" for every column. The floor is a weekly fact
 * about a decision, not a daily measurement, so it would be a fact about seven
 * days written onto one of them by a writer that races the sweep for the same
 * row.
 *
 * **A signal.** Actively harmful. `signals` is the planner's own input —
 * `Signal::scopeLive()` is the only entry point it has — so an alert written
 * there comes back next week as a *reason to publish something*, which is
 * precisely the calendar-filling instinct §4.3 says must be switched off in
 * code. It would also be swept by `signals:reap`.
 *
 * **A log line.** Not queryable per project and not renderable. §7's summary is
 * a screen an operator opens on a phone, and grep is not a query.
 *
 * So: one row per project per week, holding what the governor allowed, what the
 * planner did with it, and every refusal in between. §7's daily summary reads
 * the row for the current week and has both mandatory lines — the alert and the
 * reasons — from one query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // A date and not a timestamp: a week is identified by the Monday it
            // starts on, in the project's own timezone, which is the same
            // timezone the duty hours are read in.
            $table->date('week_start');

            // What the governor allowed and what the planner used of it. Both,
            // because the gap between them is the interesting number: a week
            // that planned 1 of 5 is a pool problem, and 2 of 2 is a throttle.
            $table->unsignedSmallInteger('ceiling');
            $table->unsignedSmallInteger('floor');
            $table->unsignedSmallInteger('planned')->default(0);

            // Why the ceiling was what it was. Nullable because "not measured"
            // is a real state and must not read as a rate of zero — see
            // `Governor::trailingReplyRate()`, which refuses to throttle on it.
            $table->double('reply_rate')->nullable();
            $table->boolean('throttled')->default(false);
            $table->unsignedSmallInteger('selection_floor');

            // §4.3's floor: "недобор — алерт оператору, а не тишина". Text
            // rather than a boolean so the screen shows a sentence, and null
            // when the floor was met so "is there an alert" is `whereNotNull`.
            $table->text('alert')->nullable();

            // Every refusal, in the planner's own words: a list of
            // {code, detail, at}. §7 makes this mandatory rather than nice to
            // have, which is why it is a column and not a log line.
            $table->jsonb('reasons')->default('[]');

            $table->timestampsTz();

            // One row per week per project. The planner is idempotent through
            // this: a second run in the same week updates the row it wrote.
            $table->unique(['project_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_plans');
    }
};
