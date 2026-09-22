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
        Schema::create('property_analytics_metrics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('measurement_read_id')->constrained()->cascadeOnDelete();
            $table->date('measured_on');
            $table->text('landing_path');
            $table->char('landing_path_hash', 64);
            $table->string('channel_group');
            $table->unsignedBigInteger('sessions');
            $table->unsignedBigInteger('purchases');
            $table->bigInteger('gross_revenue_micros');
            $table->bigInteger('refund_micros');
            $table->bigInteger('net_revenue_micros');
            $table->char('currency', 3)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'measured_on', 'landing_path_hash', 'channel_group'], 'property_analytics_path_day_unique');
        });
        // Historical hostName + session landing-path attribution was not
        // supported by GA4's semantics. Preserve it without presenting it as
        // trusted page evidence or a successful read for the new source.
        DB::table('measurement_reads')->where('source', 'ga4_landing_purchases')->update(['source' => 'ga4_legacy_unverified_origin']);
    }

    public function down(): void
    {
        Schema::dropIfExists('property_analytics_metrics');
        // Restoring an unsupported source would make old misattribution trusted.
    }
};
