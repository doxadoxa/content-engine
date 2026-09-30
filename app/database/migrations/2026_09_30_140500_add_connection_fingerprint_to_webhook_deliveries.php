<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which connection a test was sent to.
 *
 * A test signed with the old secret, or sent to the old address, can finish
 * after the owner has changed either. Its answer is about a connection that
 * no longer exists, so it must not mark the new one connected — or failed.
 * Null on every row written before this, and on every article delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->char('connection_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('connection_fingerprint');
        });
    }
};
