<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            // How many times `publish:sweep-stranded` found the delivery with
            // nobody attempting it and queued it again. Its own column rather
            // than `deferrals`, because the two are bounded separately and for
            // different reasons: a deferral is an obstacle at the receiver's
            // end (a full publishing window), a sweep is a job lost at ours.
            // Sharing one counter let a delivery put off by the window a few
            // times reach its sweep limit without ever having been swept.
            //
            // Starts at zero for existing rows. Sweeps used to be counted in
            // `deferrals`; nothing is carried over, so a row part-way through
            // its sweeps gets the full allowance again — a few extra minutes
            // of retrying, which is the safe direction to be wrong in.
            $table->unsignedSmallInteger('sweeps')->default(0)->after('deferrals');
        });

        // What `publish:sweep-stranded` asks every minute, across every
        // project: which rows that promise a job are overdue. Partial, because
        // almost every row is delivered or dead and the question is only ever
        // about the few that are not; on the expression, because that is what
        // the query compares — see App\Publishing\StrandedDeliveries, which
        // has to keep spelling both exactly this way for the index to be used.
        DB::statement(
            'create index webhook_deliveries_overdue_index on webhook_deliveries '
                ."((coalesce(next_attempt_at, created_at))) where status in ('pending', 'retrying')",
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists webhook_deliveries_overdue_index');

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('sweeps');
        });
    }
};
