<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bring a database that ran the first draft of the social tables up to date.
 *
 * **On a fresh database this does nothing, and that is the intended outcome.**
 * The three social tables were created and then corrected within the same day,
 * before any of this shipped: `signals.title`/`url` and `interactions.permalink`
 * became `text` because the raw material is a Threads post and §2 puts the
 * platform's own ceiling at 500 characters, and `project_states` gained
 * `total_sessions` because §6 asks for the *share* of social traffic and
 * direct + referral + social is not the denominator — organic and paid are
 * missing from it.
 *
 * Those corrections were made in the create-table migrations, which is the
 * right place for anyone reading the schema later. The cost is that a database
 * which had already run the originals never sees them, and the next migration
 * — the non-negative CHECK over `project_states` — then fails on a column that
 * does not exist there. This file closes that gap without a `migrate:fresh`,
 * which is not an option on a database with real work in it.
 *
 * Every step is guarded, so running it against a database built from the
 * corrected originals is a no-op rather than an error. It is deliberately
 * numbered to fall before the CHECK migration that depends on it.
 */
return new class extends Migration
{
    /** Columns that should be `text` and were first created as `varchar`. */
    private const array WIDENED = [
        'signals' => ['title', 'url'],
        'interactions' => ['permalink'],
    ];

    public function up(): void
    {
        foreach (self::WIDENED as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->isVarchar($table, $column)) {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE text");
                }
            }
        }

        if (! Schema::hasColumn('project_states', 'total_sessions')) {
            // Nullable like every other vendor figure on this table: a day when
            // Analytics was not connected has to read as "not measured" rather
            // than as a day nobody visited.
            DB::statement('ALTER TABLE project_states ADD COLUMN total_sessions integer NULL');
        }
    }

    /**
     * Deliberately empty.
     *
     * Reversing this would narrow `text` back to `varchar(255)` and truncate
     * whatever had since been stored in it, and drop a column the snapshot
     * sweep writes. Both destroy data to undo a correction, and the migration
     * this reverses is itself only a catch-up — rolling back to a state that
     * was wrong on the day it was written buys nothing.
     */
    public function down(): void {}

    private function isVarchar(string $table, string $column): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return false;
        }

        $type = DB::selectOne(
            'select data_type from information_schema.columns
             where table_name = ? and column_name = ?',
            [$table, $column],
        );

        return $type !== null && $type->data_type === 'character varying';
    }
};
