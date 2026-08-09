<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make `unsignedInteger` mean something on Postgres.
 *
 * It does not, on its own. Laravel's Postgres grammar renders
 * `$table->unsignedInteger('post_impressions')` as a plain `integer` and adds
 * no CHECK, so every counter on `project_states` — a table whose columns are
 * all counts of things that happened — has cheerfully accepted negatives since
 * it was created. The column type reads as a constraint and is a comment.
 *
 * What it costs is specific rather than theoretical. §4.3's governor cuts a
 * project's posting frequency when the trailing reply rate falls below a
 * threshold. A `post_impressions` of -5 against 3 replies gives a rate of -0.6,
 * which is below every threshold there is, so a single sign error in a gateway
 * throttles a project permanently and looks like a channel that stopped
 * working. {@see ProjectState::replyRate()} refuses the value as well, because
 * a constraint added after the data does not clean the rows that got in first;
 * this is the half that keeps the bad row out.
 *
 * One constraint rather than seventeen. The columns are the same kind of thing
 * — a count of impressions, sessions, followers, posts — with the same rule,
 * and Postgres reports the offending row in the DETAIL line either way, so
 * seventeen names would buy nothing but seventeen names.
 *
 * Deliberately *not* checked here: `post_replies <= post_impressions`. It looks
 * like the same rule and is not one. The two figures come from separate
 * Threads insights reads that can cover slightly different windows, either can
 * be null on a day the gateway was down, and a snapshot table whose job is to
 * record what the vendor said must not refuse what the vendor said. That
 * contradiction is handled where it can be handled honestly — `replyRate()`
 * returns null for it, because a rate above 1 is not a measurement.
 */
return new class extends Migration
{
    /** Every counter on the table, each of which is a count of things that happened. */
    private const array COUNTERS = [
        'brand_impressions',
        'brand_clicks',
        'total_sessions',
        'direct_sessions',
        'referral_sessions',
        'social_sessions',
        'social_engaged_sessions',
        'social_engagement_seconds',
        'conversions',
        'followers',
        'post_impressions',
        'post_replies',
        'post_likes',
        'post_reposts',
        'post_quotes',
        'posts_published',
        'replies_sent',
    ];

    public function up(): void
    {
        // `column >= 0` is unknown rather than false for a null, and an unknown
        // CHECK passes — so the nullable vendor figures stay nullable and "not
        // measured" survives, which is the one thing this table cannot lose.
        $predicate = implode(' AND ', array_map(
            static fn (string $column): string => "{$column} >= 0",
            self::COUNTERS,
        ));

        DB::statement(
            "ALTER TABLE project_states ADD CONSTRAINT project_states_counters_non_negative CHECK ({$predicate})"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE project_states DROP CONSTRAINT project_states_counters_non_negative');
    }
};
