<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an owner last switched a channel's automatic publishing off.
 *
 * A passing test now switches it on for a project that already chose
 * automatic publishing. Editing a connection resets `autopublish` to false
 * along with `verified_at`, so the flag alone cannot tell "never decided"
 * from "said no" — and a re-test after rotating a secret would otherwise
 * overrule the no.
 *
 * Every channel already switched off is counted as a no. Some of those
 * owners never decided, but nothing recorded which, and the cost of the two
 * mistakes is not the same: a channel wrongly left off asks for one
 * checkbox, one wrongly switched on publishes articles nobody approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->timestamp('autopublish_declined_at')->nullable();
        });

        DB::table('channels')->where('autopublish', false)->update(['autopublish_declined_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('autopublish_declined_at');
        });
    }
};
