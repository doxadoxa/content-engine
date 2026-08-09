<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery attempts (§2): delivery_id, status, code, latency, payload snapshot,
 * replay. Created in phase 2, written to in phase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_item_id')->nullable()
                ->constrained()->nullOnDelete();

            // The idempotency key the receiver dedupes on. Globally unique, not
            // per project: it travels alone in the request header, and a
            // receiver serving two projects has nothing else to scope it by.
            $table->uuid('delivery_id')->unique();

            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            // Exactly what was sent. A replay must resend the bytes the
            // receiver missed, not what the unit looks like now — by then the
            // unit may have been refreshed.
            $table->json('payload_snapshot');

            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['channel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
