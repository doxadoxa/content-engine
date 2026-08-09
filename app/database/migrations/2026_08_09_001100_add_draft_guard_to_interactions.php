<?php

declare(strict_types=1);

use App\Social\ReplyGuardVerdict;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the engine found wrong with its own draft (§4.2, §7).
 *
 * §7's last requirement is the one that is easy to skip: "чего движок делать не
 * стал и почему". A draft that is too long for the platform, that touches a
 * topic the Brand Brief forbids, or that failed the fact-check §10 makes
 * mandatory for a YMYL project is still put in front of the operator — with the
 * reason attached — rather than dropped somewhere only a log knows about. A
 * queue entry that silently lost its draft is indistinguishable from one the
 * engine never got to.
 *
 * A json document rather than a boolean and a string, because the screen shows
 * every finding and the send path reads the blocking ones. The shape is
 * `{"findings": [{"code": …, "detail": …, "blocking": bool}], "checked_at": …}`
 * and it is written by {@see ReplyGuardVerdict::toArray()}.
 *
 * Nullable, and null means "no draft has been guarded yet" rather than "it
 * passed" — a conversation still in `new` has nothing to say here, and reading
 * an absent document as a pass would make an undrafted reply look approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactions', function (Blueprint $table): void {
            $table->jsonb('draft_guard')->nullable()->after('draft_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table): void {
            $table->dropColumn('draft_guard');
        });
    }
};
