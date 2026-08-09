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
            // One logical publication per item revision, channel and event.
            // Replays and pings intentionally leave this null.
            $table->string('dispatch_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropUnique(['dispatch_key']);
            $table->dropColumn('dispatch_key');
        });
    }
};
