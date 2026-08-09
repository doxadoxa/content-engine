<?php

declare(strict_types=1);

use App\Integrations\Feeds\FeedReader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project's RSS whitelist (§4.1).
 *
 * §4.1 lists three intakes into the listening contour — `keyword_search`,
 * webhooks, and "RSS-вайтлист проекта" — and this is the third. It is a
 * whitelist rather than a discovery mechanism on purpose: the reactive band of
 * §5 has a TTL and a hard ceiling of one post a week, so the value is in a
 * short list of sources whose news is worth reacting to at all, not in the
 * volume a crawler could produce.
 *
 * A jsonb column on `projects` rather than a table. It is a list of strings
 * with no attributes of its own — nothing points at a feed, nothing scores one,
 * and no query filters by one — so a table would buy a join and a model in
 * exchange for nothing. `duty_hours` next door is here for the same reason.
 * The day a feed grows a last-read cursor or an error count, that is the day it
 * earns a table.
 *
 * Nullable, and null means "no feeds", which is also what an empty list means.
 * There is no third state worth distinguishing: §4.1 keeps working on
 * `keyword_search` and webhooks alone.
 *
 * Every URL here is validated on the way in by `App\Rules\PublicHttpUrl`, and
 * again on every hop of every fetch by {@see FeedReader} — an address that
 * passed validation in March can resolve to a private one in August, and this
 * column is operator-supplied input pointed at by an unattended hourly job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->jsonb('feed_urls')->nullable()->after('duty_hours');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('feed_urls');
        });
    }
};
