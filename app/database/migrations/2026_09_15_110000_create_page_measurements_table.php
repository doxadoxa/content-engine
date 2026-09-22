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
        Schema::create('measurement_reads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->date('window_from');
            $table->date('window_to');
            $table->string('status');
            $table->unsignedInteger('row_count')->default(0);
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'source', 'created_at']);
        });

        Schema::create('page_metrics', function (Blueprint $table): void {
            $this->identity($table);
            $table->unsignedBigInteger('impressions');
            $table->unsignedBigInteger('clicks');
            $table->unsignedInteger('position_tenths')->nullable();
            $table->unique(['site_page_id', 'measured_on']);
        });

        Schema::create('page_query_metrics', function (Blueprint $table): void {
            $this->identity($table);
            $table->text('query');
            $table->char('query_hash', 64);
            $table->unsignedBigInteger('impressions');
            $table->unsignedBigInteger('clicks');
            $table->unsignedInteger('position_tenths')->nullable();
            $table->unique(['site_page_id', 'measured_on', 'query_hash'], 'page_query_day_unique');
        });

        Schema::create('page_analytics_metrics', function (Blueprint $table): void {
            $this->identity($table);
            $table->string('channel_group');
            $table->unsignedBigInteger('sessions');
            $table->unsignedBigInteger('purchases');
            $table->bigInteger('gross_revenue_micros');
            $table->bigInteger('refund_micros');
            $table->bigInteger('net_revenue_micros');
            $table->char('currency', 3)->nullable();
            $table->unique(['site_page_id', 'measured_on', 'channel_group'], 'page_analytics_day_unique');
        });

        Schema::table('content_metrics', function (Blueprint $table): void {
            $table->boolean('indexed')->nullable()->default(null)->change();
        });
        // No prior false value was backed by an index-inspection API result.
        DB::table('content_metrics')->where('indexed', false)->update(['indexed' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('page_analytics_metrics');
        Schema::dropIfExists('page_query_metrics');
        Schema::dropIfExists('page_metrics');
        Schema::dropIfExists('measurement_reads');
        // Retain nullable indexing: restoring false would manufacture evidence.
    }

    private function identity(Blueprint $table): void
    {
        $table->ulid('id')->primary();
        $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
        $table->foreignUlid('site_page_id')->constrained()->cascadeOnDelete();
        $table->foreignUlid('measurement_read_id')->constrained()->cascadeOnDelete();
        $table->date('measured_on');
        $table->timestamps();
        $table->index(['project_id', 'measured_on']);
    }
};
