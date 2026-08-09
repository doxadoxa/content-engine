<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A conversation must belong to the project its channel belongs to.
 *
 * Nothing enforced that. `BelongsToProject` compares `project_id` against the
 * current tenant and stops there; it has no opinion about the channel, and the
 * channel is tenant-scoped too. So `Interaction::create(['channel_id' => …])`
 * inside `CurrentProject::run($a)` with a channel belonging to project B is
 * accepted by every layer above the database — and it is reachable from a
 * webhook payload, which is the part that makes it worth a constraint rather
 * than a code review.
 *
 * The row that results is not merely untidy. `$interaction->channel` resolves
 * through the tenant scope, the channel is in the other project, so the
 * relation comes back **null**: every reply path fatals on it and the
 * conversation sits in the duty queue forever, visible and unanswerable. §4.2
 * measures this contour in minutes.
 *
 * The fix is the composite foreign key, which is the only place the rule can be
 * stated once and hold for every writer. `channels` gains a redundant-looking
 * `UNIQUE (project_id, id)` purely to be a legal target for it — `id` is
 * already unique, so the pair adds no constraint on channels and no row is
 * refused there.
 *
 * The single-column `interactions_channel_id_foreign` is dropped rather than
 * kept alongside. Both of the new key's columns are NOT NULL, so MATCH SIMPLE
 * never skips it and it enforces everything the old one did and more; keeping
 * both would run two identical cascading deletes over the same rows on every
 * channel disconnect, and leave `down()` guessing which of the two it is
 * responsible for. Verified against the live database: deleting a channel with
 * only the composite key in place still takes its interactions with it, which
 * is the intent the old constraint carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE channels ADD CONSTRAINT channels_project_id_id_unique UNIQUE (project_id, id)');

        DB::statement('ALTER TABLE interactions DROP CONSTRAINT interactions_channel_id_foreign');

        DB::statement(
            'ALTER TABLE interactions ADD CONSTRAINT interactions_channel_in_project_foreign
             FOREIGN KEY (project_id, channel_id) REFERENCES channels (project_id, id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        // Back in the order that keeps the cascade of §3 true at every instant:
        // the single-column key is restored before the composite one goes, so a
        // channel deleted mid-rollback never leaves orphaned conversations.
        DB::statement(
            'ALTER TABLE interactions ADD CONSTRAINT interactions_channel_id_foreign
             FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE'
        );

        DB::statement('ALTER TABLE interactions DROP CONSTRAINT interactions_channel_in_project_foreign');

        DB::statement('ALTER TABLE channels DROP CONSTRAINT channels_project_id_id_unique');
    }
};
