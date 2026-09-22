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
        Schema::create('purchase_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind')->default('webhook');
            $table->text('secret')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('tracking_started_at')->nullable();
            $table->timestampTz('first_received_at')->nullable();
            $table->timestampTz('last_received_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX purchase_sources_primary_per_project ON purchase_sources (project_id) WHERE is_primary = true');

        Schema::create('purchase_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_source_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_id', 200);
            $table->unsignedBigInteger('revision');
            $table->string('status');
            $table->bigInteger('amount_minor');
            $table->bigInteger('refunded_minor')->default(0);
            $table->char('currency', 3);
            $table->timestampTz('purchased_at')->nullable();
            $table->timestampTz('occurred_at');
            $table->text('landing_url')->nullable();
            $table->string('attribution_status');
            $table->boolean('is_new_customer')->nullable();
            $table->jsonb('items')->default('[]');
            $table->text('evidence')->nullable();
            $table->char('payload_hash', 64);
            $table->timestampsTz();
            $table->unique(['purchase_source_id', 'transaction_id']);
            $table->index(['project_id', 'purchased_at']);
            $table->index(['site_page_id', 'purchased_at']);
        });
        Schema::create('purchase_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_source_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_id', 100);
            $table->char('payload_hash', 64);
            $table->jsonb('payload');
            $table->string('disposition');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at');
            $table->unique(['purchase_source_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_events');
        Schema::dropIfExists('purchase_records');
        Schema::dropIfExists('purchase_sources');
    }
};
