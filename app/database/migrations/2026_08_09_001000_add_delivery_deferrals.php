<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            // How many times the delivery was put off without being attempted:
            // the account's publishing window full (§2), or another delivery
            // already publishing the same post. Separate from `attempts`
            // because §6.2's ladder counts failures and neither of these is
            // one — and durable rather than in the cache, because the whole
            // point of counting them is to stop a delivery whose obstacle
            // never clears from re-dispatching forever.
            $table->unsignedSmallInteger('deferrals')->default(0)->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('deferrals');
        });
    }
};
