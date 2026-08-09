<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What delivery needs (§5, §6.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            // Answered by the receiver (§3 of the contract). The feedback loop
            // of phase 9 matches GSC rows against it, so a unit without one is
            // published but not measurable.
            $table->string('public_url')->nullable();
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            // `error` was text; the publisher stores a single reason string, so
            // it stays text — but the status set grew a `dead_letter` member
            // and this index is what the dashboard's dead-letter queue reads.
            $table->index(['project_id', 'status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'status', 'next_attempt_at']);
        });

        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn('public_url');
        });
    }
};
