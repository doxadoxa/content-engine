<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reason to say something (§3).
 *
 * The engine already had ideas, plans and units. What it had nowhere to put was
 * the *cause* — the question somebody asked, the news item, the price change —
 * and without it "why did we publish this" has no answer that survives the
 * week. A signal is a separate entity because it does four things a column
 * cannot: it is scored, it ages out, it is deduplicated against a window of
 * history, and it is pointed at afterwards by whatever it produced.
 *
 * The instants here are `timestamptz` rather than the plain `timestamp` the
 * earlier tables use. These are the first columns in the schema compared
 * against wall-clock *now* by a reaper and a dedup window while a project's own
 * timezone is what decides when its day starts (§4.3), and a naive column makes
 * that comparison depend on the session's timezone setting. New timestamp
 * columns in the social tables carry the zone.
 *
 * ⚠️ **Two traps in `signals_external_id_unique` for whoever writes the 12.3
 * gateway.**
 *
 * The natural ingest call — `Signal::upsert($rows, ['project_id', 'source',
 * 'external_id'], [...])` — does not work against this index and fails loudly
 * with SQLSTATE 42P10, "there is no unique or exclusion constraint matching the
 * ON CONFLICT specification". Postgres matches a partial unique index only when
 * the statement restates its predicate, and Laravel's `upsert()` emits an
 * `ON CONFLICT (columns)` clause with no `WHERE`. The way through is raw SQL
 * with the predicate repeated: `... ON CONFLICT (project_id, source,
 * external_id) WHERE external_id IS NOT NULL DO UPDATE SET ...`. Rows with a
 * null external_id are outside the index and have to be deduplicated on the
 * fingerprint instead, which is what §4.1 asks for anyway.
 *
 * And the index is named by hand rather than by Laravel's convention, so
 * `dropUnique(['project_id', 'source', 'external_id'])` looks for
 * `signals_project_id_source_external_id_unique` and finds nothing. Drop it by
 * its real name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            $table->string('kind');
            $table->string('source');

            // The platform's own id, when there is one. Null for the sources
            // that have no identifier of their own — a corpus gap or a seasonal
            // curve is something we noticed, not something we were handed.
            $table->string('external_id')->nullable();

            // Normalised topic hash. §4.1 asks for dedup against 30 days of
            // publications *and* against the queue, and neither can key on the
            // external id: one hot subject arrives five times from four sources
            // with five different ids and produces five nearly identical
            // drafts. Computed in PHP by Signal::fingerprintFor() so the same
            // definition serves the listener and the planner.
            $table->string('fingerprint');

            // Text rather than varchar(255): the raw material is a Threads post,
            // and §2 puts the platform's own ceiling at 500 characters. A
            // listener that truncates the thing it is listening to is worse
            // than one that stores a long row.
            $table->text('title');
            $table->text('url')->nullable();

            // What the signal is about, in the project's own entity vocabulary.
            // §4.3 makes resolving into that vocabulary a hard gate on a native
            // post, so the resolution happens once here rather than per draft.
            $table->jsonb('entities')->default('[]');

            $table->timestampTz('occurred_at');

            // §5's TTL, stored as the absolute moment it dies rather than as a
            // duration next to occurred_at. A query can filter on this; a
            // duration would make the reaper compute a deadline per row and
            // scan the whole table to find the handful that expired.
            $table->timestampTz('expires_at')->nullable();

            // 0..100. Small on purpose: this is a ranking aid, not a
            // measurement, and a wider column invites somebody to put a
            // currency in it.
            $table->smallInteger('weight')->default(0);

            $table->jsonb('raw')->default('{}');

            // When a plan or a draft actually used it. The other half of "petля
            // учится по источнику" — an intake producing signals nobody
            // consumes is an intake to switch off, and that is only visible if
            // consumption is recorded rather than inferred.
            $table->timestampTz('consumed_at')->nullable();

            $table->timestamps();

            // The dedup window of §4.1: this project's signals about this
            // subject, newest first. Leading with project_id keeps it usable
            // under the tenant scope, which every read here carries.
            $table->index(['project_id', 'fingerprint', 'occurred_at']);

            // The planner's query — "what questions came in this week" — and
            // the per-kind slices §5 budgets against.
            $table->index(['project_id', 'kind', 'occurred_at']);

            // The reaper's query. Rows with a null expires_at never expire and
            // are still in this index, which is the cheaper trade: the reaper
            // runs often and the table is small.
            $table->index(['project_id', 'expires_at']);
        });

        // One row per thing the platform showed us.
        //
        // Partial for size, not for semantics — and the distinction is worth
        // writing down because it is easy to get backwards. Postgres compares
        // nulls as distinct inside a unique index by default, so a plain
        // `UNIQUE (project_id, source, external_id)` would already accept any
        // number of rows with a null external_id. The `WHERE` clause therefore
        // changes no behaviour; it keeps the idless sources — a corpus gap, a
        // seasonal curve — out of an index that exists to deduplicate the ones
        // the platform hands us an id for.
        DB::statement(
            'CREATE UNIQUE INDEX signals_external_id_unique
             ON signals (project_id, source, external_id) WHERE external_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
